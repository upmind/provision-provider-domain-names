<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\DomainNames\Tests\Unit\CentralNicReseller\Helper;

use Metaregistrar\EPP\eppContact;
use Metaregistrar\EPP\eppException;
use Metaregistrar\EPP\eppInfoContactRequest;
use Metaregistrar\EPP\eppInfoContactResponse;
use Upmind\ProvisionProviders\DomainNames\CentralNicReseller\Data\Configuration;
use Upmind\ProvisionProviders\DomainNames\Tests\TestCase;
use Upmind\ProvisionProviders\DomainNames\CentralNicReseller\EppExtension\EppConnection;
use Upmind\ProvisionProviders\DomainNames\CentralNicReseller\Helper\EppHelper;

class EppHelperTest extends TestCase
{
    public function test_get_contact_info_with_null_country_code(): void
    {
        $contactId = 'TEST123';

        // Mock the EPP response
        $mockResponse = $this->createMock(eppInfoContactResponse::class);
        $mockResponse->method('getContactName')->willReturn('John Doe');
        $mockResponse->method('getContactEmail')->willReturn('john@example.com');
        $mockResponse->method('getContactVoice')->willReturn('+1.1234567890');
        $mockResponse->method('getContactCompanyname')->willReturn('Test Company');
        $mockResponse->method('getContactStreet')->willReturn('123 Main St');
        $mockResponse->method('getContactCity')->willReturn('Test City');
        $mockResponse->method('getContactProvince')->willReturn('Test State');
        $mockResponse->method('getContactZipcode')->willReturn('12345');
        $mockResponse->method('getContactCountrycode')->willReturn(null);

        $mockContact = $this->createMock(eppContact::class);
        $mockContact->method('getType')->willReturn('loc');
        $mockResponse->method('getContact')->willReturn($mockContact);

        // Mock the connection
        $mockConnection = $this->createMock(EppConnection::class);
        $mockConnection->method('request')->willReturn($mockResponse);

        // Mock the configuration
        $mockConfiguration = $this->createMock(Configuration::class);

        // Create EppHelper instance with mocked dependencies
        $eppHelper = new EppHelper($mockConnection, $mockConfiguration);

        // Call the method
        $result = $eppHelper->getContactInfo($contactId);

        // Assert the country code is null
        $this->assertNull($result->country_code);
    }

    public function test_get_contact_info_with_xx_country_code_returns_null(): void
    {
        $contactId = 'TEST123';

        // Mock the EPP response
        $mockResponse = $this->createMock(eppInfoContactResponse::class);
        $mockResponse->method('getContactName')->willReturn('John Doe');
        $mockResponse->method('getContactEmail')->willReturn('john@example.com');
        $mockResponse->method('getContactVoice')->willReturn('+1.1234567890');
        $mockResponse->method('getContactCompanyname')->willReturn('Test Company');
        $mockResponse->method('getContactStreet')->willReturn('123 Main St');
        $mockResponse->method('getContactCity')->willReturn('Test City');
        $mockResponse->method('getContactProvince')->willReturn('Test State');
        $mockResponse->method('getContactZipcode')->willReturn('12345');
        $mockResponse->method('getContactCountrycode')->willReturn('XX');

        $mockContact = $this->createMock(eppContact::class);
        $mockContact->method('getType')->willReturn('loc');
        $mockResponse->method('getContact')->willReturn($mockContact);

        // Mock the connection
        $mockConnection = $this->createMock(EppConnection::class);
        $mockConnection->method('request')->willReturn($mockResponse);

        // Mock the configuration
        $mockConfiguration = $this->createMock(Configuration::class);

        // Create EppHelper instance with mocked dependencies
        $eppHelper = new EppHelper($mockConnection, $mockConfiguration);

        // Call the method
        $result = $eppHelper->getContactInfo($contactId);

        // Assert the country code is normalized to null
        $this->assertNull($result->country_code, 'Country code "XX" should be normalized to null');
    }

    public function test_get_contact_info_with_valid_country_code(): void
    {
        $contactId = 'TEST123';

        // Mock the EPP response
        $mockResponse = $this->createMock(eppInfoContactResponse::class);
        $mockResponse->method('getContactName')->willReturn('John Doe');
        $mockResponse->method('getContactEmail')->willReturn('john@example.com');
        $mockResponse->method('getContactVoice')->willReturn('+52.1234567890');
        $mockResponse->method('getContactCompanyname')->willReturn('Test Company');
        $mockResponse->method('getContactStreet')->willReturn('123 Main St');
        $mockResponse->method('getContactCity')->willReturn('Mexico City');
        $mockResponse->method('getContactProvince')->willReturn('CDMX');
        $mockResponse->method('getContactZipcode')->willReturn('01000');
        $mockResponse->method('getContactCountrycode')->willReturn('MX');

        $mockContact = $this->createMock(eppContact::class);
        $mockContact->method('getType')->willReturn('loc');
        $mockResponse->method('getContact')->willReturn($mockContact);

        // Mock the connection
        $mockConnection = $this->createMock(EppConnection::class);
        $mockConnection->method('request')->willReturn($mockResponse);

        // Mock the configuration
        $mockConfiguration = $this->createMock(Configuration::class);

        // Create EppHelper instance with mocked dependencies
        $eppHelper = new EppHelper($mockConnection, $mockConfiguration);

        // Call the method
        $result = $eppHelper->getContactInfo($contactId);

        // Assert the country code is preserved
        $this->assertEquals('MX', $result->country_code);
    }

    public function test_get_contact_info_returns_null_for_non_existent_contact(): void
    {
        $contactId = 'NONEXISTENT';

        // Mock the connection to throw 2303 error (Object does not exist)
        $mockConnection = $this->createMock(EppConnection::class);
        $mockConnection->method('request')->willThrowException(new eppException('Object does not exist', 2303));

        // Mock the configuration
        $mockConfiguration = $this->createMock(Configuration::class);

        // Create EppHelper instance with mocked dependencies
        $eppHelper = new EppHelper($mockConnection, $mockConfiguration);

        // Call the method
        $result = $eppHelper->getContactInfo($contactId);

        // Assert null is returned for non-existent contact
        $this->assertNull($result);
    }
}
