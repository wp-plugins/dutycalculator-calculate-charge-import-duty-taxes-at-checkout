=== DutyCalculator: Calculate & charge import duty & taxes at checkout ===
Contributors: DutyCalculator
Tags:  import duty, DDP shipping, landed cost, import tax, duty calculator, international shipping, charge import duty, checkout
Requires at least: 3.3
Tested up to: 4.2.2
Stable tag: 1.0.6
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

== Description ==
Calculate & charge import duty & taxes at checkout to provide your international customers with a landed cost DDP service.

This plugin calculates accurate import duty & taxes in the checkout, so you can offer your international customers a total landed cost service. With the DutyCalculator plugin your international customers can make informed purchase decisions, with the confidence that they will not face any unexpected customs charges upon delivery. You will have happier customers, a smoother delivery process and you will have fewer return shipments and unpaid custom charges.

= Who is using DutyCalculator? =
DutyCalculator API and plugins are used by many brands and retailers that care about their customer experience, such as Christian Louboutin, Selfridges, Threadless, Farfetch and many more.

= Coverage =
DutyCalculator covers [141 countries](http://www.dutycalculator.com/help_center/what-countries-are-covered-by-the-dutycalculator/) and over [500,000 products](http://www.dutycalculator.com/hs-codes-import-duty-tax-rates-restriction/).

= Requirements =
The plugin requires a DutyCalculator [API account](http://www.dutycalculator.com/compare-plans/) and is made to work with WooCommerce version 2.3.10 and higher.

== Installation ==
The Plugin can be installed directly from the main WordPress Plugin page.

1. Enter your DutyCalculator API key on the extension settings page.
2. Select required destination countries and if you require HS codes for confirmed orders
3. Insert DutyCalculator row to at least one of the tax rate classes on the WooCommerce tax settings.
4. Make sure you make the incoterms for your shipments DDP

== Frequently Asked Questions ==
= 1. Are the import duty & tax calculations accurate? =
Yes, we keep duty & tax rates up to date continuously. Key to accurate calculations is that you classify your products correctly in the Woocommerce Products section.
= 2. Do I need to classify all products? =
This is not required. In case you have not classified a product DutyCalculator will classify the product automatically based on the product title and description. 
= 3. What are the HS tariff codes for? =
As an additional service, we can provide you with the destination country HS tariff codes for confirmed orders. We advise you to add these HS tariff codes to the commercial invoice and packing list of the shipment, to avoid mismatches between import duty & taxes calculated and actually charged. This is not a free service, you will be charged “Get HS code” rate as per your account plan.
= 4. Where can I get support? =
To contact customer support go to http://www.dutycalculator.com/team/. Make sure to be logged into your account. 
= 5. Where can I find documentation? =
To find our API documentation go to http://www.dutycalculator.com/api-center/dutycalculator-api-2-1-documentation/
= 6. Where can I file a bug? =
To contact tech support go to http://www.dutycalculator.com/team/. Make sure to be logged into your account.

== Screenshots ==
1. Import duty & taxes in checkout
2. Import duty & taxes in order total, with HS codes
3. Backend: Import duty & taxes in order total, with HS codes
4. Backend: Ability to select duty category for each product
5. Backend: Add tax line for DutyCalculator
6. Backend: Extension provisioning

== Changelog ==

= 1.0.6 =
* Taxes don't calculate for the non-taxable products

= 1.0.5 =
* Adaptation to WooCommerce 2.3.10

= 1.0.4 =
* Display prices during cart/checkout: Including tax. Adapted cart/checkout message in case of calculation failed.

= 1.0.3 =
* Adaptation to WooCommerce 2.1.10

= 1.0.2 =
* Response SimpleXML bugfix

= 1.0.1 =
* Fixed product meta data update

= 1.0.0 =
* Initial Release

== Upgrade Notice ==

= 1.0.0 =
* Initial Release
