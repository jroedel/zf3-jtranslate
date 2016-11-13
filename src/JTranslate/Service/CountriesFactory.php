<?php
namespace JTranslate\Service;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\Json\Json;
use JTranslate\Model\CountriesInfo;

/**
 * Factory responsible of priming the CountriesInfo service
 *
 * @author Jeff Roedel <jeff.roedel@schoenstatt-fathers.org>
 */
class CountriesFactory implements FactoryInterface
{
    /**
     * {@inheritDoc}
     *
     * @return CreateTimelineEventForm
     * 
     * @todo make sure this always works...
     */
    public function createService(ServiceLocatorInterface $serviceLocator)
    {
        $countries = Json::decode(file_get_contents("vendor/mledoze/countries/dist/countries.json"));
		$obj = new CountriesInfo($countries);
		return $obj;
    }
}