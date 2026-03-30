<div class="box">
  {if $internautenb2boffer_reorder_status != ''}
    <p class="alert alert-warning">
      {l s='The offer could not be transferred to checkout. Please contact support.' mod='internautenb2boffer'}
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
</div>
