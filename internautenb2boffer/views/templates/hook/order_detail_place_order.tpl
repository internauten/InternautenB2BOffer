{*
  Shows the "Place accepted offer" action on the order detail page, an expiry notice, or the order confirmation.
  Copyright (c) 2026 die.internauten.ch GmbH
  License: MIT
*}
<div class="box">
  {if $internautenb2boffer_offer_state == 'ordered'}
    <p class="alert alert-success">
      {l s='This offer has been ordered.' mod='internautenb2boffer'}
      <a href="{$internautenb2boffer_new_order_url|escape:'htmlall':'UTF-8'}">{l s='View order' mod='internautenb2boffer'}</a>
    </p>
  {elseif $internautenb2boffer_place_order_url != ''}
    {if $internautenb2boffer_reorder_status == 'failed'}
      <p class="alert alert-warning">
        {l s='The offer could not be ordered. Please contact support.' mod='internautenb2boffer'}
      </p>
    {/if}
    <p>
      {l s='Your offer has been accepted. You can place this order without changes.' mod='internautenb2boffer'}
    </p>
    <p>
      <a class="btn btn-primary" href="{$internautenb2boffer_place_order_url|escape:'htmlall':'UTF-8'}">
        {l s='Place accepted offer now' mod='internautenb2boffer'}
      </a>
    </p>
  {else}
    <p class="alert alert-info">
      {l s='This offer has expired and can no longer be ordered. Please request a new offer.' mod='internautenb2boffer'}
    </p>
  {/if}
</div>
