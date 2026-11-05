@domain @country
Feature: Every country uses different formatting for addresses
  Therefore we store that inside the country it self

  Background:
    Given the site operates on a store in "Austria"
    And the countries address format is "{{ street }} {{ number }}\n{{ postcode }} {{ city }}\n{{ country.name }}"

  Scenario: Format the address to given format
    Given there is an address with country "Austria", "4600", "Wels", "Freiung", "9-11/N3"
    Then the address should format to "Freiung 9-11/N3\n4600 Wels\nAustria"

  Scenario: Format an address without a country with the default format
    Given there is an address without a country, "4600", "Wels", "Freiung", "9-11/N3"
    Then the address should format to a text containing "Freiung 9-11/N3"
    And the address should format to a text containing "4600 Wels"

  Scenario: Format the address with the default format when the country has none
    Given the countries address format is ""
    And there is an address with country "Austria", "4600", "Wels", "Freiung", "9-11/N3"
    Then the address should format to a text containing "Freiung 9-11/N3"
    And the address should format to a text containing "4600 Wels"
    And the address should format to a text containing "Austria"
