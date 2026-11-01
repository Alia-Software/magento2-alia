# Alia for Magento

Connects a Magento Open Source or Adobe Commerce store to [Alia](https://www.aliapopups.com) for popups, discount codes and revenue reporting.

Requires Magento 2.4.4 or later.

## Install

```bash
composer require alia/magento2
bin/magento module:enable Alia_Connect
bin/magento setup:upgrade
bin/magento setup:di:compile   # production mode only
bin/magento cache:flush
```

## Connect

1. In the Magento admin, go to **System > Extensions > Integrations**.
2. Find **Alia** and click **Activate**, then **Allow**.
3. Sign in to Alia in the window that opens and choose which websites to connect.

If your browser blocks the window, allow pop-ups for your Magento admin and click **Activate** again.

A copy of your store on another address, such as a staging site, doesn't show popups until you connect it as its own store.

## Orders

Alia reports the revenue its popups bring in, so the module sends your orders to Alia from a scheduled task, `alia_send_orders`, in its own cron group, `alia`. It runs every minute with Magento's normal cron (`bin/magento cron:run`), so make sure cron is running. If Alia stops hearing from the store, it shows that orders are delayed. Popups and discount codes keep working.

Orders created in the last 30 days are sent. Each order carries its number, store, state, payment method, email, billing phone, customer id, totals in the base currency, coupon code, and the first order it replaced if it was edited. No addresses or line items are sent. Requests are signed with the integration's secret, and nothing is sent until the integration is activated.

To tell which popup a visitor saw, the Alia script stores the visitor's Alia id in a cookie, `alia_visitor`, once the store's consent settings allow tracking, and removes it when they don't. The module saves it against the visitor's cart in its own table, `alia_quote_visitor`, which Magento clears along with old carts.

## Uninstall

Uninstall with Magento's command rather than only `composer remove`, which would leave behind an Alia integration that can't be deleted. The command also removes the module's table.

```bash
bin/magento module:uninstall --non-composer Alia_Connect
composer remove alia/magento2
bin/magento setup:upgrade
```

## Support

Email help@alialearn.com.
