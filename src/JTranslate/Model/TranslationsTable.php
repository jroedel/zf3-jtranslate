<?php
namespace JTranslate\Model;

use Zend\Db\Adapter\AdapterAwareInterface;
use Zend\Db\TableGateway\AbstractTableGateway;
use Zend\Db\Adapter\Adapter;
use Zend\Db\TableGateway\TableGatewayInterface;
use Zend\Cache\Storage\StorageInterface;
use Zend\Db\Adapter\AdapterInterface;
use Zend\Db\Sql\Sql;
use Zend\Db\Sql\Where;
use ZfcUser\Entity\UserInterface;
use SamUser\Model\UserTable;
use Zend\Code\Generator\ValueGenerator;
use Zend\Code\Generator\FileGenerator;

class TranslationsTable extends AbstractTableGateway implements AdapterAwareInterface
{
    /**
     * 
     * @var array $phrasesInDb
     */
    protected $phrasesInDb;
    
    /**
     * 
     * @var array
     */
    protected $translationsCache;
    /**
     * 
     * @var array $tableCache
     */
    protected $phrasesCache;
    /**
     * 
     * @var array $newMissingTranslations
     */
    protected $newMissingPhrases;
    
    /**
     * 
     * @var AdapterInterface
     */
    protected $adapter;
    
    /**
     * 
     * @var TableGatewayInterface $phrasesGateway
     */
    protected $phrasesGateway;

    /**
     *
     * @var TableGatewayInterface $translationsGateway
     */
    protected $translationsGateway;
    
    /**
     * 
     * @var \Iterator
     */
    protected $config;
    
    /**
     * 
     * @var StorageInterface $cache
     */
    protected $cache;
    
    /**
     * 
     * @var UserInterface
     */
    protected $actingUser;
    
    /**
     * 
     * @var UserTable
     */
    protected $userTable;
    
    /**
     * 
     * @var array
     */
    protected $arrayFilePatterns;
    
    /**
     * 
     * @param TableGatewayInterface $gateway
     * @param StorageInterface $cache
     * @param unknown $config
     */
    public function __construct($phrasesGateway, $translationsGateway, $cache, $config, $actingUser, $userTable)
    {
        $this->phrasesGateway       = $phrasesGateway;
        $this->translationsGateway  = $translationsGateway;
        $this->adapter              = $phrasesGateway->getAdapter();
        $this->cache                = $cache;
        $this->config               = $config;
        $this->phrasesInDb          = $this->getPhraseKeysFromDb();
        $this->actingUser           = $actingUser;
        $this->userTable            = $userTable;
        $this->newMissingPhrases    = array();
    }

    /**
     *  Set db adapter
     *  
     *  @param Adapter $adapter
     *  @return self
     */
    public function setDbAdapter(Adapter $adapter) 
    { 
         $this->adapter = $adapter; 
         $this->initialize(); 
         return $this;
    }
    
    /**
     * 
     * @return array
     */
    public function getTranslations()
    {
        if ($this->translationsCache) {
            return $this->translationsCache;
        }
        $sql = "SELECT t.`translation_id`,p.`translation_phrase_id`, t.`locale`,t.`translation`,
t.`modified_by`,t.`modified_on`, p.`text_domain`,  p.`phrase`, p.`added_on`
FROM `trans_phrases` p
LEFT JOIN `trans_translations` t ON p.`translation_phrase_id` = t.`translation_phrase_id`
WHERE (p.`project` = ?)
ORDER BY `text_domain`, `phrase`";
        $sqlParams = array($this->config['project_name']);
        $results = $this->fetchSome(null, $sql, $sqlParams);
        
        $utc = new \DateTimeZone('UTC');
        $userTable = $this->getUserTable();
        $return = array();
        foreach ($results as $tran) { //@todo avoid setting null locale keys for records without any translations
            if (isset($return[$tran['translation_phrase_id']])) { //if we already already have an entry for this phrase
                $return[$tran['translation_phrase_id']][$tran['locale']] = $tran['translation'];
                $return[$tran['translation_phrase_id']][$tran['locale'].'Id'] = $tran['translation_id'];
                $return[$tran['translation_phrase_id']][$tran['locale'].'ModifiedBy'] = $tran['modified_by'] ? 
                        $userTable->getUser($tran['modified_by']) : null;
                $return[$tran['translation_phrase_id']][$tran['locale'].'ModifiedOn'] = $tran['modified_on'] ? 
                        new \DateTime($tran['modified_on'], $utc) : null;
            } else {
                $return[$tran['translation_phrase_id']] = array(
                    'phraseId' => $tran['translation_phrase_id'],
                    $tran['locale'] => $tran['translation'],
                    $tran['locale'].'Id' => $tran['translation_id'],
                    $tran['locale'].'ModifiedBy' => $tran['modified_by'] ? 
                        $userTable->getUser($tran['modified_by']) : null,
                    $tran['locale'].'ModifiedOn' => $tran['modified_on'] ? 
                        new \DateTime($tran['modified_on'], $utc) : null,
                    'textDomain' => $tran['text_domain'],
                    'phrase' => $tran['phrase'],
                    'addedOn' => $tran['added_on'],
                );
            }
        }
        $this->translationsCache = $return;
        return $return;
    }
    
    public function getOutstandingTranslationCount()
    {
        $sql = "SELECT p.`translation_phrase_id`, COUNT(*) AS PhraseLocaleCount
FROM `trans_phrases` p
LEFT JOIN `trans_translations` t ON p.`translation_phrase_id` = t.`translation_phrase_id`
WHERE (`project` = ?)
GROUP BY translation_phrase_id
HAVING PhraseLocaleCount < ?";
        $sqlParams = array($this->config['project_name'], count($this->config['locales_to_translate'])+1);
        $results = $this->fetchSome(null, $sql, $sqlParams);
        if (!$results) { 
            return 0;
        } else {
            return count($results);
        }
    }
    
    public function getPhrase($id)
    {
        if ($this->translationsCache) {
            return $this->translationsCache[$id];
        }
        return $this->getTranslations()[$id];
    }
    
    public function updatePhrase($id, $data)
    {
        $phrase = $this->getTranslations()[$id];
        $dateString = date_format((new \DateTime(null, new \DateTimeZone('UTC'))), 'Y-m-d H:i:s');

        $results = array();
        foreach ($this->getLocales(true) as $key => $value) {
            if (!isset($data[$key]) || !$data[$key] || 
                (isset($phrase[$key]) && $data[$key] === $phrase[$key])) { //in the case that they didn't write anything, continue
                continue;
            }
            if (isset($data[$key.'Id']) && $data[$key.'Id'] && $phrase[$key.'Id']) { //if we have a translation id for the locale
                //update don't insert
                $sql = new Sql($this->adapter);
                $update = $sql->update($this->config['translations_table_name'])
                    ->set(array(
                        'translation' => $data[$key],
                        'modified_on' => $dateString,
                        'modified_by' => $this->actingUser->id
                    ))
                    ->where(array('translation_id' => $data[$key.'Id']));
                $statement = $sql->prepareStatementForSqlObject($update);
                $results[] = $statement->execute();
            } else {
                //insert then
                $sql = new Sql($this->adapter);
                $insert = $sql->insert($this->config['translations_table_name'])
                ->values(array(
                    'translation_phrase_id' => $data['phraseId'],
                    'locale' => $key,
                    'translation' => $data[$key],
                    'modified_on' => $dateString,
                    'modified_by' => $this->actingUser->id
                ));
                $statement = $sql->prepareStatementForSqlObject($insert);
                $results[] = $statement->execute();
            }
        }
        return $results;
    }
    
    /**
     * 
     * @return string[]
     */
    public function getLocales($shouldIncludeKeyLocale = false)
    {
        $return = array();
        $localeNames = $this->getLocaleNames();
        $locales = $this->config['locales_to_translate'];
        if ($shouldIncludeKeyLocale && $this->config && isset($this->config['key_locale']) && 
            !in_array($this->config['key_locale'], $locales)) {
            array_push($locales, $this->config['key_locale']);
        }
        foreach ($locales as $locale) {
            if (key_exists($locale, $localeNames)) {
                $return[$locale] = $localeNames[$locale];
            }
        }
        return $return;
    }
    
    /**
     * 
     * @return string[][]
     */
    public function getPhraseKeysFromDb()
    {
        if ($this->phrasesCache) {
            return $this->phrasesCache;
        }
        $where = new Sql($this->adapter);
        $where  ->select($this->config['phrases_table_name'])
                ->columns(array(
                    'translation_phrase_id',
                    'project',
                    'text_domain',
                    'phrase',
                    'added_on',
                ))
                ->where(array('project' => $this->config['project_name']))
                ->order(array('project', 'text_domain', 'phrase'));
        $results = $this->fetchSome($where);
        $return = array();
        foreach ($results as $tran) {
            if (key_exists($tran['text_domain'], $return)) {
                array_push($return[$tran['text_domain']], $tran['phrase']);
            } else {
                $return[$tran['text_domain']] = array(
                    $tran['phrase']
                );
            }
        }
        $this->phrasesCache = $return;
        return $return;
    }

    /**
     * Add to the list of translations to add to the database
     * @param array $params
     */
    protected function addMissingPhrase($params)
    {
        if (!key_exists($params['text_domain'], $this->phrasesInDb)) {
            $this->phrasesInDb[$params['text_domain']] = array($params['message']);
        } else {
            $this->phrasesInDb[$params['text_domain']][] = $params['message'];
        }
        if (!key_exists($params['text_domain'], $this->newMissingPhrases)) {
            $this->newMissingPhrases[$params['text_domain']] = array($params['message']);
        } else {
            $this->newMissingPhrases[$params['text_domain']][] = $params['message'];
        }
        return $this;
    }
    
    /**
     * 
     * @param array $params
     */
    public function reportMissingTranslation($params)
    {
        if (!key_exists($params['text_domain'], $this->phrasesInDb) ||
            !in_array($params['message'], $this->phrasesInDb[$params['text_domain']])) {
            $this->addMissingPhrase($params);
        }
        return $this;
    }
    
    /**
     * Returns the translated text of the db in a 4-dimensional array
     * @return string[][][]
     */
    public function getTranslatedText()
    {
        $sql = "SELECT t.`translation_id`,p.`translation_phrase_id`, 
t.`locale`, t.`translation`, p.`text_domain`,  p.`phrase`
FROM `trans_phrases` p
INNER JOIN `trans_translations` t ON p.`translation_phrase_id` = t.`translation_phrase_id`
WHERE (p.`project` = ?)
ORDER BY `locale`, `text_domain`, `phrase`";
        $sqlParams = array($this->config['project_name']);
        $results = $this->fetchSome(null, $sql, $sqlParams);
        
        $return = array();
        foreach ($results as $tran) {
            if (isset($return[$tran['text_domain']])) {
                if (isset($return[$tran['text_domain']][$tran['locale']])) {
                    $return[$tran['text_domain']][$tran['locale']][$tran['phrase']] = $tran['translation'];
                } else {
                    $return[$tran['text_domain']][$tran['locale']] = array(
                        $tran['phrase'] => $tran['translation']
                    );
                }
            } else {
                $return[$tran['text_domain']] = array(
                    $tran['locale'] => array(
                        $tran['phrase'] => $tran['translation']
                    )
                );
            }
        }
        return $return;
    }
    
    /**
     * Queries the database for the latest translations and rewrites all the files. 
     * 
     */
    public function writePhpTranslationArrays()
    {
        $translations = $this->getTranslatedText();
        $test = array();
        foreach ($translations as $textDomain => $localeTrans) {
            if (key_exists($textDomain, $this->arrayFilePatterns)) {
                foreach ($localeTrans as $locale => $trans) {
                    $generator = new ValueGenerator($trans, 'array');
                    $file = FileGenerator::fromArray(array(
                        'body' => 'return '.$generator->generate().';',
                    ));
                    $code = $file->generate();
                    $test[$this->arrayFilePatterns[$textDomain]['base_dir'].'/'.
                        sprintf($this->arrayFilePatterns[$textDomain]['pattern'], 
                        $locale)] = $code;
                    file_put_contents($this->arrayFilePatterns[$textDomain]['base_dir'].'/'.
                        sprintf($this->arrayFilePatterns[$textDomain]['pattern'], 
                        $locale), $code);
                }
            }
        }
    }
    
    public function writeMissingPhrasesToDb()
    {
        $dateString = date_format((new \DateTime(null, new \DateTimeZone('UTC'))), 'Y-m-d H:i:s');
        $result = array();
        foreach ($this->newMissingPhrases as $textDomain => $phrases) {
            foreach ($phrases as $phrase) {
                if (is_null($phrase)) {
                    continue;
                }
                var_dump([
                    'text_domain' => $textDomain,
                    'phrase' => $phrase
                ]);
                //insert into phrases table
                $sql = new Sql($this->adapter);
                $insert =
                $sql->insert($this->config['phrases_table_name'])
                    ->values(array(
                        'project' => $this->config['project_name'],
                        'text_domain' => $textDomain,
                        'phrase' => $phrase,
                        'added_on' => $dateString
                    ));
                $statement = $sql->prepareStatementForSqlObject($insert);
                $lastResult = $statement->execute();
                $result[] = $lastResult;
                
                //auto insert into translations table for the key locale
                $phrasesKeyId = $lastResult->getGeneratedValue();
                $sql = new Sql($this->adapter);
                $insert =
                $sql->insert($this->config['translations_table_name'])
                ->values(array(
                    'translation_phrase_id' => $phrasesKeyId,
                    'locale' => $this->config['key_locale'],
                    'translation' => $phrase,
                    'modified_by' => $this->actingUser->id,
                    'modified_on' => $dateString,
                ));
                $statement = $sql->prepareStatementForSqlObject($insert);
                $lastResult = $statement->execute();
                $result[] = $lastResult;
                
            }
        }
        return $result;
    }
    
//     public function writeMissingCountryNamesToDb()
//     {
        
//     }
    
    public function setArrayFilePatterns($patterns)
    {
        $this->arrayFilePatterns = $patterns;
        return $this;
    }
    
    public function getUserTable()
    {
        if (!$this->userTable) {
            throw \Exception('User table not loaded into TranslationsTable');
        } 
        return $this->userTable;
    }
    
    /**
     * @param Where|\Closure|string|array $where
     * @param string
     * @param array
     * @return array
     */
    public function fetchSome($where, $sql = null, $sqlArgs = null, $gateway = null)
    {
        if (null===$gateway) {
            $gateway = $this->phrasesGateway;
        }
        if (is_null($where) && is_null($sql)) {
            throw \InvalidArgumentException('No query requested.');
        }
        if (!is_null($sql))
        {
	        if (is_null($sqlArgs)) {
	            $sqlArgs = Adapter::QUERY_MODE_EXECUTE; //make sure query executes
	        }
            $result = $this->adapter->query($sql, $sqlArgs);
        } else {
            $result = $gateway->select($where);
        }
    
        $return = array();
        foreach ($result as $row) {
            $return[] = $row;
        }
        return $return;
    }
    
    protected function getLocaleNames()
    {
        return array(
    'af_NA' => 'Afrikaans (Namibia)',
    'af_ZA' => 'Afrikaans (South Africa)',
    'af' => 'Afrikaans',
    'ak_GH' => 'Akan (Ghana)',
    'ak' => 'Akan',
    'sq_AL' => 'Albanian (Albania)',
    'sq' => 'Albanian',
    'am_ET' => 'Amharic (Ethiopia)',
    'am' => 'Amharic',
    'ar_DZ' => 'Arabic (Algeria)',
    'ar_BH' => 'Arabic (Bahrain)',
    'ar_EG' => 'Arabic (Egypt)',
    'ar_IQ' => 'Arabic (Iraq)',
    'ar_JO' => 'Arabic (Jordan)',
    'ar_KW' => 'Arabic (Kuwait)',
    'ar_LB' => 'Arabic (Lebanon)',
    'ar_LY' => 'Arabic (Libya)',
    'ar_MA' => 'Arabic (Morocco)',
    'ar_OM' => 'Arabic (Oman)',
    'ar_QA' => 'Arabic (Qatar)',
    'ar_SA' => 'Arabic (Saudi Arabia)',
    'ar_SD' => 'Arabic (Sudan)',
    'ar_SY' => 'Arabic (Syria)',
    'ar_TN' => 'Arabic (Tunisia)',
    'ar_AE' => 'Arabic (United Arab Emirates)',
    'ar_YE' => 'Arabic (Yemen)',
    'ar' => 'Arabic',
    'hy_AM' => 'Armenian (Armenia)',
    'hy' => 'Armenian',
    'as_IN' => 'Assamese (India)',
    'as' => 'Assamese',
    'asa_TZ' => 'Asu (Tanzania)',
    'asa' => 'Asu',
    'az_Cyrl' => 'Azerbaijani (Cyrillic)',
    'az_Cyrl_AZ' => 'Azerbaijani (Cyrillic, Azerbaijan)',
    'az_Latn' => 'Azerbaijani (Latin)',
    'az_Latn_AZ' => 'Azerbaijani (Latin, Azerbaijan)',
    'az' => 'Azerbaijani',
    'bm_ML' => 'Bambara (Mali)',
    'bm' => 'Bambara',
    'eu_ES' => 'Basque (Spain)',
    'eu' => 'Basque',
    'be_BY' => 'Belarusian (Belarus)',
    'be' => 'Belarusian',
    'bem_ZM' => 'Bemba (Zambia)',
    'bem' => 'Bemba',
    'bez_TZ' => 'Bena (Tanzania)',
    'bez' => 'Bena',
    'bn_BD' => 'Bengali (Bangladesh)',
    'bn_IN' => 'Bengali (India)',
    'bn' => 'Bengali',
    'bs_BA' => 'Bosnian (Bosnia and Herzegovina)',
    'bs' => 'Bosnian',
    'bg_BG' => 'Bulgarian (Bulgaria)',
    'bg' => 'Bulgarian',
    'my_MM' => 'Burmese (Myanmar [Burma])',
    'my' => 'Burmese',
    'ca_ES' => 'Catalan (Spain)',
    'ca' => 'Catalan',
    'tzm_Latn' => 'Central Morocco Tamazight (Latin)',
    'tzm_Latn_MA' => 'Central Morocco Tamazight (Latin, Morocco)',
    'tzm' => 'Central Morocco Tamazight',
    'chr_US' => 'Cherokee (United States)',
    'chr' => 'Cherokee',
    'cgg_UG' => 'Chiga (Uganda)',
    'cgg' => 'Chiga',
    'zh_Hans' => 'Chinese (Simplified Han)',
    'zh_Hans_CN' => 'Chinese (Simplified Han, China)',
    'zh_Hans_HK' => 'Chinese (Simplified Han, Hong Kong SAR China)',
    'zh_Hans_MO' => 'Chinese (Simplified Han, Macau SAR China)',
    'zh_Hans_SG' => 'Chinese (Simplified Han, Singapore)',
    'zh_Hant' => 'Chinese (Traditional Han)',
    'zh_Hant_HK' => 'Chinese (Traditional Han, Hong Kong SAR China)',
    'zh_Hant_MO' => 'Chinese (Traditional Han, Macau SAR China)',
    'zh_Hant_TW' => 'Chinese (Traditional Han, Taiwan)',
    'zh' => 'Chinese',
    'kw_GB' => 'Cornish (United Kingdom)',
    'kw' => 'Cornish',
    'hr_HR' => 'Croatian (Croatia)',
    'hr' => 'Croatian',
    'cs_CZ' => 'Czech (Czech Republic)',
    'cs' => 'Czech',
    'da_DK' => 'Danish (Denmark)',
    'da' => 'Danish',
    'nl_BE' => 'Dutch (Belgium)',
    'nl_NL' => 'Dutch (Netherlands)',
    'nl' => 'Dutch',
    'ebu_KE' => 'Embu (Kenya)',
    'ebu' => 'Embu',
    'en_AS' => 'English (American Samoa)',
    'en_AU' => 'English (Australia)',
    'en_BE' => 'English (Belgium)',
    'en_BZ' => 'English (Belize)',
    'en_BW' => 'English (Botswana)',
    'en_CA' => 'English (Canada)',
    'en_GU' => 'English (Guam)',
    'en_HK' => 'English (Hong Kong SAR China)',
    'en_IN' => 'English (India)',
    'en_IE' => 'English (Ireland)',
    'en_JM' => 'English (Jamaica)',
    'en_MT' => 'English (Malta)',
    'en_MH' => 'English (Marshall Islands)',
    'en_MU' => 'English (Mauritius)',
    'en_NA' => 'English (Namibia)',
    'en_NZ' => 'English (New Zealand)',
    'en_MP' => 'English (Northern Mariana Islands)',
    'en_PK' => 'English (Pakistan)',
    'en_PH' => 'English (Philippines)',
    'en_SG' => 'English (Singapore)',
    'en_ZA' => 'English (South Africa)',
    'en_TT' => 'English (Trinidad and Tobago)',
    'en_UM' => 'English (U.S. Minor Outlying Islands)',
    'en_VI' => 'English (U.S. Virgin Islands)',
    'en_GB' => 'English (United Kingdom)',
    'en_US' => 'English (United States)',
    'en_ZW' => 'English (Zimbabwe)',
    'en' => 'English',
    'eo' => 'Esperanto',
    'et_EE' => 'Estonian (Estonia)',
    'et' => 'Estonian',
    'ee_GH' => 'Ewe (Ghana)',
    'ee_TG' => 'Ewe (Togo)',
    'ee' => 'Ewe',
    'fo_FO' => 'Faroese (Faroe Islands)',
    'fo' => 'Faroese',
    'fil_PH' => 'Filipino (Philippines)',
    'fil' => 'Filipino',
    'fi_FI' => 'Finnish (Finland)',
    'fi' => 'Finnish',
    'fr_BE' => 'French (Belgium)',
    'fr_BJ' => 'French (Benin)',
    'fr_BF' => 'French (Burkina Faso)',
    'fr_BI' => 'French (Burundi)',
    'fr_CM' => 'French (Cameroon)',
    'fr_CA' => 'French (Canada)',
    'fr_CF' => 'French (Central African Republic)',
    'fr_TD' => 'French (Chad)',
    'fr_KM' => 'French (Comoros)',
    'fr_CG' => 'French (Congo - Brazzaville)',
    'fr_CD' => 'French (Congo - Kinshasa)',
    'fr_CI' => 'French (Côte d’Ivoire)',
    'fr_DJ' => 'French (Djibouti)',
    'fr_GQ' => 'French (Equatorial Guinea)',
    'fr_FR' => 'French (France)',
    'fr_GA' => 'French (Gabon)',
    'fr_GP' => 'French (Guadeloupe)',
    'fr_GN' => 'French (Guinea)',
    'fr_LU' => 'French (Luxembourg)',
    'fr_MG' => 'French (Madagascar)',
    'fr_ML' => 'French (Mali)',
    'fr_MQ' => 'French (Martinique)',
    'fr_MC' => 'French (Monaco)',
    'fr_NE' => 'French (Niger)',
    'fr_RW' => 'French (Rwanda)',
    'fr_RE' => 'French (Réunion)',
    'fr_BL' => 'French (Saint Barthélemy)',
    'fr_MF' => 'French (Saint Martin)',
    'fr_SN' => 'French (Senegal)',
    'fr_CH' => 'French (Switzerland)',
    'fr_TG' => 'French (Togo)',
    'fr' => 'French',
    'ff_SN' => 'Fulah (Senegal)',
    'ff' => 'Fulah',
    'gl_ES' => 'Galician (Spain)',
    'gl' => 'Galician',
    'lg_UG' => 'Ganda (Uganda)',
    'lg' => 'Ganda',
    'ka_GE' => 'Georgian (Georgia)',
    'ka' => 'Georgian',
    'de_AT' => 'German (Austria)',
    'de_BE' => 'German (Belgium)',
    'de_DE' => 'German (Germany)',
    'de_LI' => 'German (Liechtenstein)',
    'de_LU' => 'German (Luxembourg)',
    'de_CH' => 'German (Switzerland)',
    'de' => 'German',
    'el_CY' => 'Greek (Cyprus)',
    'el_GR' => 'Greek (Greece)',
    'el' => 'Greek',
    'gu_IN' => 'Gujarati (India)',
    'gu' => 'Gujarati',
    'guz_KE' => 'Gusii (Kenya)',
    'guz' => 'Gusii',
    'ha_Latn' => 'Hausa (Latin)',
    'ha_Latn_GH' => 'Hausa (Latin, Ghana)',
    'ha_Latn_NE' => 'Hausa (Latin, Niger)',
    'ha_Latn_NG' => 'Hausa (Latin, Nigeria)',
    'ha' => 'Hausa',
    'haw_US' => 'Hawaiian (United States)',
    'haw' => 'Hawaiian',
    'he_IL' => 'Hebrew (Israel)',
    'he' => 'Hebrew',
    'hi_IN' => 'Hindi (India)',
    'hi' => 'Hindi',
    'hu_HU' => 'Hungarian (Hungary)',
    'hu' => 'Hungarian',
    'is_IS' => 'Icelandic (Iceland)',
    'is' => 'Icelandic',
    'ig_NG' => 'Igbo (Nigeria)',
    'ig' => 'Igbo',
    'id_ID' => 'Indonesian (Indonesia)',
    'id' => 'Indonesian',
    'ga_IE' => 'Irish (Ireland)',
    'ga' => 'Irish',
    'it_IT' => 'Italian (Italy)',
    'it_CH' => 'Italian (Switzerland)',
    'it' => 'Italian',
    'ja_JP' => 'Japanese (Japan)',
    'ja' => 'Japanese',
    'kea_CV' => 'Kabuverdianu (Cape Verde)',
    'kea' => 'Kabuverdianu',
    'kab_DZ' => 'Kabyle (Algeria)',
    'kab' => 'Kabyle',
    'kl_GL' => 'Kalaallisut (Greenland)',
    'kl' => 'Kalaallisut',
    'kln_KE' => 'Kalenjin (Kenya)',
    'kln' => 'Kalenjin',
    'kam_KE' => 'Kamba (Kenya)',
    'kam' => 'Kamba',
    'kn_IN' => 'Kannada (India)',
    'kn' => 'Kannada',
    'kk_Cyrl' => 'Kazakh (Cyrillic)',
    'kk_Cyrl_KZ' => 'Kazakh (Cyrillic, Kazakhstan)',
    'kk' => 'Kazakh',
    'km_KH' => 'Khmer (Cambodia)',
    'km' => 'Khmer',
    'ki_KE' => 'Kikuyu (Kenya)',
    'ki' => 'Kikuyu',
    'rw_RW' => 'Kinyarwanda (Rwanda)',
    'rw' => 'Kinyarwanda',
    'kok_IN' => 'Konkani (India)',
    'kok' => 'Konkani',
    'ko_KR' => 'Korean (South Korea)',
    'ko' => 'Korean',
    'khq_ML' => 'Koyra Chiini (Mali)',
    'khq' => 'Koyra Chiini',
    'ses_ML' => 'Koyraboro Senni (Mali)',
    'ses' => 'Koyraboro Senni',
    'lag_TZ' => 'Langi (Tanzania)',
    'lag' => 'Langi',
    'lv_LV' => 'Latvian (Latvia)',
    'lv' => 'Latvian',
    'lt_LT' => 'Lithuanian (Lithuania)',
    'lt' => 'Lithuanian',
    'luo_KE' => 'Luo (Kenya)',
    'luo' => 'Luo',
    'luy_KE' => 'Luyia (Kenya)',
    'luy' => 'Luyia',
    'mk_MK' => 'Macedonian (Macedonia)',
    'mk' => 'Macedonian',
    'jmc_TZ' => 'Machame (Tanzania)',
    'jmc' => 'Machame',
    'kde_TZ' => 'Makonde (Tanzania)',
    'kde' => 'Makonde',
    'mg_MG' => 'Malagasy (Madagascar)',
    'mg' => 'Malagasy',
    'ms_BN' => 'Malay (Brunei)',
    'ms_MY' => 'Malay (Malaysia)',
    'ms' => 'Malay',
    'ml_IN' => 'Malayalam (India)',
    'ml' => 'Malayalam',
    'mt_MT' => 'Maltese (Malta)',
    'mt' => 'Maltese',
    'gv_GB' => 'Manx (United Kingdom)',
    'gv' => 'Manx',
    'mr_IN' => 'Marathi (India)',
    'mr' => 'Marathi',
    'mas_KE' => 'Masai (Kenya)',
    'mas_TZ' => 'Masai (Tanzania)',
    'mas' => 'Masai',
    'mer_KE' => 'Meru (Kenya)',
    'mer' => 'Meru',
    'mfe_MU' => 'Morisyen (Mauritius)',
    'mfe' => 'Morisyen',
    'naq_NA' => 'Nama (Namibia)',
    'naq' => 'Nama',
    'ne_IN' => 'Nepali (India)',
    'ne_NP' => 'Nepali (Nepal)',
    'ne' => 'Nepali',
    'nd_ZW' => 'North Ndebele (Zimbabwe)',
    'nd' => 'North Ndebele',
    'nb_NO' => 'Norwegian Bokmål (Norway)',
    'nb' => 'Norwegian Bokmål',
    'nn_NO' => 'Norwegian Nynorsk (Norway)',
    'nn' => 'Norwegian Nynorsk',
    'nyn_UG' => 'Nyankole (Uganda)',
    'nyn' => 'Nyankole',
    'or_IN' => 'Oriya (India)',
    'or' => 'Oriya',
    'om_ET' => 'Oromo (Ethiopia)',
    'om_KE' => 'Oromo (Kenya)',
    'om' => 'Oromo',
    'ps_AF' => 'Pashto (Afghanistan)',
    'ps' => 'Pashto',
    'fa_AF' => 'Persian (Afghanistan)',
    'fa_IR' => 'Persian (Iran)',
    'fa' => 'Persian',
    'pl_PL' => 'Polish (Poland)',
    'pl' => 'Polish',
    'pt_BR' => 'Portuguese (Brazil)',
    'pt_GW' => 'Portuguese (Guinea-Bissau)',
    'pt_MZ' => 'Portuguese (Mozambique)',
    'pt_PT' => 'Portuguese (Portugal)',
    'pt' => 'Portuguese',
    'pa_Arab' => 'Punjabi (Arabic)',
    'pa_Arab_PK' => 'Punjabi (Arabic, Pakistan)',
    'pa_Guru' => 'Punjabi (Gurmukhi)',
    'pa_Guru_IN' => 'Punjabi (Gurmukhi, India)',
    'pa' => 'Punjabi',
    'ro_MD' => 'Romanian (Moldova)',
    'ro_RO' => 'Romanian (Romania)',
    'ro' => 'Romanian',
    'rm_CH' => 'Romansh (Switzerland)',
    'rm' => 'Romansh',
    'rof_TZ' => 'Rombo (Tanzania)',
    'rof' => 'Rombo',
    'ru_MD' => 'Russian (Moldova)',
    'ru_RU' => 'Russian (Russia)',
    'ru_UA' => 'Russian (Ukraine)',
    'ru' => 'Russian',
    'rwk_TZ' => 'Rwa (Tanzania)',
    'rwk' => 'Rwa',
    'saq_KE' => 'Samburu (Kenya)',
    'saq' => 'Samburu',
    'sg_CF' => 'Sango (Central African Republic)',
    'sg' => 'Sango',
    'seh_MZ' => 'Sena (Mozambique)',
    'seh' => 'Sena',
    'sr_Cyrl' => 'Serbian (Cyrillic)',
    'sr_Cyrl_BA' => 'Serbian (Cyrillic, Bosnia and Herzegovina)',
    'sr_Cyrl_ME' => 'Serbian (Cyrillic, Montenegro)',
    'sr_Cyrl_RS' => 'Serbian (Cyrillic, Serbia)',
    'sr_Latn' => 'Serbian (Latin)',
    'sr_Latn_BA' => 'Serbian (Latin, Bosnia and Herzegovina)',
    'sr_Latn_ME' => 'Serbian (Latin, Montenegro)',
    'sr_Latn_RS' => 'Serbian (Latin, Serbia)',
    'sr' => 'Serbian',
    'sn_ZW' => 'Shona (Zimbabwe)',
    'sn' => 'Shona',
    'ii_CN' => 'Sichuan Yi (China)',
    'ii' => 'Sichuan Yi',
    'si_LK' => 'Sinhala (Sri Lanka)',
    'si' => 'Sinhala',
    'sk_SK' => 'Slovak (Slovakia)',
    'sk' => 'Slovak',
    'sl_SI' => 'Slovenian (Slovenia)',
    'sl' => 'Slovenian',
    'xog_UG' => 'Soga (Uganda)',
    'xog' => 'Soga',
    'so_DJ' => 'Somali (Djibouti)',
    'so_ET' => 'Somali (Ethiopia)',
    'so_KE' => 'Somali (Kenya)',
    'so_SO' => 'Somali (Somalia)',
    'so' => 'Somali',
    'es_AR' => 'Spanish (Argentina)',
    'es_BO' => 'Spanish (Bolivia)',
    'es_CL' => 'Spanish (Chile)',
    'es_CO' => 'Spanish (Colombia)',
    'es_CR' => 'Spanish (Costa Rica)',
    'es_DO' => 'Spanish (Dominican Republic)',
    'es_EC' => 'Spanish (Ecuador)',
    'es_SV' => 'Spanish (El Salvador)',
    'es_GQ' => 'Spanish (Equatorial Guinea)',
    'es_GT' => 'Spanish (Guatemala)',
    'es_HN' => 'Spanish (Honduras)',
    'es_419' => 'Spanish (Latin America)',
    'es_MX' => 'Spanish (Mexico)',
    'es_NI' => 'Spanish (Nicaragua)',
    'es_PA' => 'Spanish (Panama)',
    'es_PY' => 'Spanish (Paraguay)',
    'es_PE' => 'Spanish (Peru)',
    'es_PR' => 'Spanish (Puerto Rico)',
    'es_ES' => 'Spanish (Spain)',
    'es_US' => 'Spanish (United States)',
    'es_UY' => 'Spanish (Uruguay)',
    'es_VE' => 'Spanish (Venezuela)',
    'es' => 'Spanish',
    'sw_KE' => 'Swahili (Kenya)',
    'sw_TZ' => 'Swahili (Tanzania)',
    'sw' => 'Swahili',
    'sv_FI' => 'Swedish (Finland)',
    'sv_SE' => 'Swedish (Sweden)',
    'sv' => 'Swedish',
    'gsw_CH' => 'Swiss German (Switzerland)',
    'gsw' => 'Swiss German',
    'shi_Latn' => 'Tachelhit (Latin)',
    'shi_Latn_MA' => 'Tachelhit (Latin, Morocco)',
    'shi_Tfng' => 'Tachelhit (Tifinagh)',
    'shi_Tfng_MA' => 'Tachelhit (Tifinagh, Morocco)',
    'shi' => 'Tachelhit',
    'dav_KE' => 'Taita (Kenya)',
    'dav' => 'Taita',
    'ta_IN' => 'Tamil (India)',
    'ta_LK' => 'Tamil (Sri Lanka)',
    'ta' => 'Tamil',
    'te_IN' => 'Telugu (India)',
    'te' => 'Telugu',
    'teo_KE' => 'Teso (Kenya)',
    'teo_UG' => 'Teso (Uganda)',
    'teo' => 'Teso',
    'th_TH' => 'Thai (Thailand)',
    'th' => 'Thai',
    'bo_CN' => 'Tibetan (China)',
    'bo_IN' => 'Tibetan (India)',
    'bo' => 'Tibetan',
    'ti_ER' => 'Tigrinya (Eritrea)',
    'ti_ET' => 'Tigrinya (Ethiopia)',
    'ti' => 'Tigrinya',
    'to_TO' => 'Tonga (Tonga)',
    'to' => 'Tonga',
    'tr_TR' => 'Turkish (Turkey)',
    'tr' => 'Turkish',
    'uk_UA' => 'Ukrainian (Ukraine)',
    'uk' => 'Ukrainian',
    'ur_IN' => 'Urdu (India)',
    'ur_PK' => 'Urdu (Pakistan)',
    'ur' => 'Urdu',
    'uz_Arab' => 'Uzbek (Arabic)',
    'uz_Arab_AF' => 'Uzbek (Arabic, Afghanistan)',
    'uz_Cyrl' => 'Uzbek (Cyrillic)',
    'uz_Cyrl_UZ' => 'Uzbek (Cyrillic, Uzbekistan)',
    'uz_Latn' => 'Uzbek (Latin)',
    'uz_Latn_UZ' => 'Uzbek (Latin, Uzbekistan)',
    'uz' => 'Uzbek',
    'vi_VN' => 'Vietnamese (Vietnam)',
    'vi' => 'Vietnamese',
    'vun_TZ' => 'Vunjo (Tanzania)',
    'vun' => 'Vunjo',
    'cy_GB' => 'Welsh (United Kingdom)',
    'cy' => 'Welsh',
    'yo_NG' => 'Yoruba (Nigeria)',
    'yo' => 'Yoruba',
    'zu_ZA' => 'Zulu (South Africa)',
    'zu' => 'Zulu'
          );
    }
}