{**
 * Rendered on the order completion page. preparePaymentProcess() normally
 * redirects before this is reached; this fragment covers the case where the
 * headers were already sent, and shows a recoverable error otherwise.
 *}
{if isset($flizError) && $flizError}
    {row}
        {col}
            <div class="alert alert-danger" role="alert">{$flizError|escape:'html'}</div>
            {if isset($flizStatusUrl) && $flizStatusUrl}
                {button type="link" href=$flizStatusUrl variant="secondary"}
                    {$flizToOrderStatus|escape:'html'}
                {/button}
            {/if}
        {/col}
    {/row}
{elseif isset($flizRedirectUrl) && $flizRedirectUrl}
    {row}
        {col}
            <p>{$flizRedirectNotice|escape:'html'}</p>
            {button type="link" href=$flizRedirectUrl variant="primary"}
                {$flizPayNow|escape:'html'}
            {/button}
        {/col}
    {/row}
    <script>
        window.location.replace('{$flizRedirectUrl|escape:'javascript'}');
    </script>
    <noscript>
        <meta http-equiv="refresh" content="0; URL={$flizRedirectUrl|escape:'html'}">
    </noscript>
{/if}
