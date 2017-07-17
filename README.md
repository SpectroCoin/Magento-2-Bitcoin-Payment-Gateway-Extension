SpectroCoin Bitcoin Merchant plugin for Magento 2
---------------
This is [SpectroCoin Bitcoin Payment Extension for Magento 2](https://spectrocoin.com/en/plugins/accept-bitcoin-magento2.html). This extenstion allows to easily accept bitcoins (and other cryptocurrencies such as DASH) at your Magento 2 website.

To succesfully use this plugin, you have to have a SpectroCoin Bitcoin wallet. You can get it [here](https://spectrocoin.com/en/bitcoin-wallet.html). Also you have to create a merchant project to get Merchant and Project IDs, to do so create a new merchant project [here](https://spectrocoin.com/en/merchant/api/create.html).

**INSTALLATION**

This plugin can be installed different ways:

* Copy files and directories to your server

### Copying files and directories to your server

Connect to your server by ssh: 

`$ ssh user:password@server`

Go to the magento web-root: 

`user$ cd /var/www`

Create directories for the extension: 

`user$ mkdir -p app/code/Spectrocoin/Merchant`
 
Clone plugin:

`user$ git clone git@bitbucket.org:alexshiii/spectrocoin-magento2.git ./app/code/Spectrocoin/Merchant`

Run magento:
 
`user$ bin/magento setup:upgrade`


**CONFIGURATION**

1. Generate private and public keys
	1. Automatically<br />
	
	Go to [SpectroCoin](https://spectrocoin.com/) -> [Project list](https://spectrocoin.com/en/merchant/api/list.html)
	click on your project, then select "Edit Project and then click "Generate" (next to Public key field), as a result you will get an automatically generated private key, download and save it. The matching Public key will be generated automatically and added to your project.
	
	2. Manually<br />
    	
	Private key:
    ```shell
    # generate a 2048-bit RSA private key
    openssl genrsa -out "C:\private" 2048
	
    ```
    <br />
    	Public key:
    ```shell
    # output public key portion in PEM format
    openssl rsa -in "C:\private" -pubout -outform PEM -out "C:\public"
    ```
	<br />

2. Login in admin panel of your Magento 2 web-shop

3. Navigate to "Stores -> Configuration -> Sales -> Payment methods"

4. Click on "Bitcoin via Spectrocoin" and fill the form enter your Merchant ID, Project ID and private key.

**INFORMATION** 

This plugin has been developed by SpectroCoin.com
If you need any further support regarding our services you can contact us via:<br />
E-mail: [info@spectrocoin.com](mailto:info@spectrocoin.com)<br />
Phone: +442037697306<br />
Skype: [spectrocoin_merchant](skype:spectrocoin_merchant)<br />
Web: [https://spectrocoin.com](https://spectrocoin.com)<br />
Twitter: [@spectrocoin](https://twitter.com/spectrocoin)<br />
Facebook: [SpectroCoin](https://www.facebook.com/spectrocoin)<br />
