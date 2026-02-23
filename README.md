# Internauten B2B Offer

Payment module that adds a "Get Offer" payment method. Customers place the order and it is set to a pending "Awaiting Get Offer" state so you can review and respond with a custom offer.

## Features

- Adds a checkout payment method: Get Offer.
- Creates a pending order in "Awaiting Get Offer" state.
- Adds two extra order states for your workflow: "Offer accepted" and "Offer rejected".
- Custom order confirmation message for this payment method.

## Installation

See [Zum Modul README](internautenb2boffer/README.md#installation)

## Development

### Prepare Dev Environemt

If your Shop is running on Azure best would be that you use a copy of the production system.
To clone a Unix VM on Azure, follow these steps:

1. Stop the VM and deallocate it.
2. Create a snapshot of the OS disk or create a managed image.
3. Create a new VM and choose the snapshot/image as the source.
4. Set the VM size, network, and name for the new copy.
5. Start the new VM and verify it boots correctly.
6. Change URL in nginx
    ```bash
    sudo nano /etc/nginx/sites-available/prestashop
    sudo nginx -s reload
    ```
7. create new certificat
    ```bash
    sudo certbot --nginx
    sudo nginx -s reload
    ```

8. Delete Cache
    ```bash
    cd /var/www/prestashop/
    sudo rm -rf var/cache/*
    sudo reboot
    ```
9. Set Prestashop URL   
    Use propriate SQL Client, adjust url and shop id    
    ```sql
    UPDATE prestashop.ps_shop_url SET `domain`='your.new.test.url',`domain_ssl`='your.new.test.url' WHERE id_shop_url=1;
    ```

### Get Module and install it

1. git clone yor fork of this repo
    ```bash
    cd ~ 
    git clone https://github.com/yourgithub/InternautenB2BOffer.git
    ```
2. set owner, goup and rights
    ```bash
    sudo chown -R www-data:www-data ~/InternautenB2BOffer/internautenb2boffer
    ```
3. Create symlink and set group:owner
    ```bash
    sudo ln -s ~/InternautenB2BOffer/internautenb2boffer /var/www/prestashop/modules/internautenb2boffer
    sudo chown -h www-data:www-data ~/InternautenB2BOffer/internautenb2boffer
    sudo chown -h www-data:www-data /var/www/prestashop/modules/internautenb2boffer
    ```
4. Activate and configure Module in Prestashop   
    In Prestashop backend go to Module Manager / not installed Modules and install the module. 
