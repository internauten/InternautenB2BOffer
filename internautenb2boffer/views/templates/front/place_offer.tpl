{*
  Confirmation page for ordering an accepted offer as a binding order with payment by invoice.
  Copyright (c) 2026 die.internauten.ch GmbH
  License: MIT
*}
{extends file='customer/page.tpl'}

{block name='page_title'}
  {l s='Order offer' mod='internautenb2boffer'}
{/block}

{block name='page_content'}
  <div class="box">
    <p>
      {l s='Offer:' mod='internautenb2boffer'} <strong>{$internautenb2boffer_reference|escape:'htmlall':'UTF-8'}</strong><br>
      {l s='Total:' mod='internautenb2boffer'} <strong>{$internautenb2boffer_total|escape:'htmlall':'UTF-8'}</strong><br>
      {l s='Payment: by invoice' mod='internautenb2boffer'}
    </p>
    <p>{l s='Do you want to place this offer as a binding order?' mod='internautenb2boffer'}</p>
    <form method="post" action="{$internautenb2boffer_action_url|escape:'htmlall':'UTF-8'}">
      <input type="hidden" name="token" value="{$internautenb2boffer_token|escape:'htmlall':'UTF-8'}">
      <button type="submit" name="submitPlaceOffer" value="1" class="btn btn-primary">
        {l s='Place binding order' mod='internautenb2boffer'}
      </button>
      <a class="btn btn-secondary" href="{$internautenb2boffer_detail_url|escape:'htmlall':'UTF-8'}">
        {l s='Cancel' mod='internautenb2boffer'}
      </a>
    </form>
  </div>
{/block}
