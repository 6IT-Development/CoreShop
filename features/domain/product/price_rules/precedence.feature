@domain @product
Feature: Product price rule precedence
  In order to control which price applies, the rule with the lowest priority is the most important one:
  the first valid rule that sets a price wins, and a product specific rule with stop propagation
  also stops the global price rules

  Background:
    Given the site operates on a store in "Austria"
    And the site has a currency "Euro" with iso "EUR"
    And I am in country "Austria"
    And the site has a product "Shoe" priced at 10000
    And the site has a product "Shoe 2" priced at 15000

  Scenario: The price of the rule with the lowest priority wins
    Given adding a product price rule named "price 90"
    And the price rule is active
    And the price rule has priority "0"
    And the price rule has a action price of 90 in currency "EUR"
    And adding a product price rule named "price 120"
    And the price rule is active
    And the price rule has priority "10"
    And the price rule has a action price of 120 in currency "EUR"
    Then the product "Shoe" should have the prices, price: "9000" and retail-price: "9000" and discount: "0"

  Scenario: The discount price of the rule with the lowest priority wins
    Given adding a product price rule named "discount price 70"
    And the price rule is active
    And the price rule has priority "10"
    And the price rule has a action discount-price of 70 in currency "EUR"
    And adding a product price rule named "discount price 80"
    And the price rule is active
    And the price rule has priority "5"
    And the price rule has a action discount-price of 80 in currency "EUR"
    Then the product "Shoe" should have the prices, price: "8000" and discount-price: "8000" and retail-price: "10000" and discount: "0"

  Scenario: A product specific rule with stop propagation stops the global price rules
    Given adding a product price rule named "discount 10"
    And the price rule is active
    And the price rule has a action discount-percent with 10% discount
    And adding a product specific price rule to product "Shoe" named "discount 20"
    And the specific price rule is active
    And the specific price rule is stop propagation
    And the specific price rule has a action discount-percent with 20% discount
    Then the product "Shoe" should have the prices, price: "8000" and retail-price: "10000" and discount: "2000"
    And the product "Shoe 2" should have the prices, price: "13500" and retail-price: "15000" and discount: "1500"

  Scenario: A product specific rule without stop propagation adds to the global price rules
    Given adding a product price rule named "discount 10"
    And the price rule is active
    And the price rule has a action discount-percent with 10% discount
    And adding a product specific price rule to product "Shoe" named "discount 20"
    And the specific price rule is active
    And the specific price rule has a action discount-percent with 20% discount
    Then the product "Shoe" should have the prices, price: "7000" and retail-price: "10000" and discount: "3000"
