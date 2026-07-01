# Order Return Bundle

The CoreShop Order Return Bundle provides functionality for handling customer order returns, including form submission, PDF generation, and notification management.

## Configuration

To enable or disable the bundle, use the `enabled` option in your configuration:

```yaml
core_shop_order_return:
    enabled: true
```
Default value is `true`.

**Configure Storage Folder**: The bundle requires a folder to store the generated return objects.
This is configured via the `coreshop.folder.order_return` parameter. Ensure this folder exists or is automatically created.
Default value is `coreshop/order_returns`.

## Serializer / Normalizer

The `OrderReturnNormalizer` provides the following data structure when normalizing an `OrderReturn` object:

- `id`: The unique identifier of the return.
- `returnedAt`: The date and time when the return was initiated.
- `customer`: An array containing customer details:
    - `firstname`: First name of the customer.
    - `lastname`: Last name of the customer.
    - `fullName`: Combined first and last name.
    - `email`: Customer email address.
- `pdfPath`: The full URL to the generated return PDF asset.
- `notification`: Status of the return notification:
    - `sent`: Boolean flag indicating if the notification was sent.
    - `sentAt`: Timestamp when the notification was sent.
    - `data`: Additional notification data.
- `order`: Brief information about the related order:
    - `id`: The internal ID of the order.
    - `number`: The order number.

## Static Routes

The bundle utilizes a static route for the frontend return form:

- **Route Name**: `coreshop_order_return_form`
- **Pattern**: `/order-return`
- **Controller**: `CoreShop\Bundle\OrderReturnBundle\Controller\OrderReturnController::returnFormAction`

This route provides the entry point for customers to submit their return requests.

## Installation and Update

If you are adding the Order Return Bundle to an existing CoreShop installation:

**Import Class Definitions**: You need to import the `CoreShopOrderReturn` class definition. The JSON definition is located at:

```src/CoreShop/Bundle/OrderReturnBundle/Resources/install/pimcore/classes/CoreShopOrderReturn.json```

You can use the Pimcore CLI to rebuild the class:

   ```bash
   bin/console pimcore:deployment:classes-rebuild -c CoreShopOrderReturn
   ```
   You can use the CoreShop resource install CLI command
   ```bash
   bin/console coreshop:resources:install --application-name=coreshop --update-classes
   ```
   You can use the CoreShop installer with update-classes option
   ```bash
   bin/console coreshop:install --update-classes
   ```

## Generated Objects and Assets

### OrderReturn Objects
Upon form submission, a new Pimcore object of class `CoreShopOrderReturn` is created. These objects are stored in the folder defined by the `coreshop.folder.order_return` parameter. The object key is generated as `order-return-[uniqid]`.

### Generated PDF Asset
A PDF document is automatically generated for each return request using the `@CoreShopOrderReturn/OrderReturn/pdf.html.twig` template.

- **Storage Location**: The PDF is saved as a Pimcore Asset under `/coreshop_order_return/pdf-[uniqid]/`.
- **Filename Format**: `[Firstname]-[Lastname]-order-return-[OrderNumber].pdf` (sanitized).
- **Association**: The generated asset is automatically linked to the `OrderReturn` object in the `pdfAttachment` field.

## EventListener
You can use a simple Symfony EventListener to perform the operation when the object is updated.
