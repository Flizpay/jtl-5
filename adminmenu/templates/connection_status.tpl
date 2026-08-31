{**
 * Backend tab "Status" — connection state, cashback and open payments.
 *}
{foreach $flizMessages as $message}
    <div class="alert alert-{$message.type|escape:'html'}">{$message.text|escape:'html'}</div>
{/foreach}

<div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span>{d__('flizpay', 'Connection to FLIZpay')}</span>
        {if $flizConnected}
            <span class="badge badge-success badge-pill">{d__('flizpay', 'connected')}</span>
        {else}
            <span class="badge badge-danger badge-pill">{d__('flizpay', 'not connected')}</span>
        {/if}
    </div>
    <div class="card-body">
        {if !$flizConnected}
            <div class="alert alert-warning">
                {d__('flizpay', 'FLIZpay is not offered at checkout until the connection is complete.')}
                {if !$flizApiKeySet}
                    {d__('flizpay', 'First enter your API key under "Settings".')}
                {elseif !$flizWebhookKeySet}
                    {d__('flizpay', 'The webhook key is missing. Please reconnect.')}
                {elseif !$flizWebhookAlive}
                    {d__('flizpay', 'Waiting for the FLIZpay test notification. The shop must be publicly accessible (no password protection, no IP restriction, and a valid SSL certificate).')}
                {/if}
            </div>
        {/if}

        <table class="table table-sm mb-0">
            <tbody>
                <tr>
                    <th style="width:32%">API-Key</th>
                    <td>{if $flizApiKeySet}{d__('flizpay', 'set')}{else}<em>{d__('flizpay', 'not set')}</em>{/if}</td>
                </tr>
                <tr>
                    <th>{d__('flizpay', 'Webhook key')}</th>
                    <td>{if $flizWebhookKeySet}{d__('flizpay', 'available')}{else}<em>{d__('flizpay', 'missing')}</em>{/if}</td>
                </tr>
                <tr>
                    <th>Webhook-URL</th>
                    <td>
                        <code>{$flizWebhookUrl|escape:'html'}</code>
                        {if $flizWebhookUrl !== $flizExpectedWebhookUrl}
                            <div class="text-danger small mt-1">
                                {d__('flizpay', 'The registered URL differs from the shop URL')}
                                (<code>{$flizExpectedWebhookUrl|escape:'html'}</code>).
                                {d__('flizpay', 'Please reconnect.')}
                            </div>
                        {/if}
                    </td>
                </tr>
                <tr>
                    <th>{d__('flizpay', 'Test notification')}</th>
                    <td>
                        {if $flizWebhookAlive}{d__('flizpay', 'received')}{else}<em>{d__('flizpay', 'pending')}</em>{/if}
                        {if $flizLastWebhookAt}
                            <span class="text-muted small">({d__('flizpay', 'last notification')}: {$flizLastWebhookAt|escape:'html'})</span>
                        {/if}
                    </td>
                </tr>
                <tr>
                    <th>{d__('flizpay', 'Discount / cashback')}</th>
                    <td>
                        {if $flizCashback}
                            {d__('flizpay', 'First purchase')}: {$flizCashback.first_purchase_amount|string_format:"%.2f"} % ·
                            {d__('flizpay', 'Repeat purchases')}: {$flizCashback.standard_amount|string_format:"%.2f"} %
                        {else}
                            <em>{d__('flizpay', 'no active discount')}</em>
                        {/if}
                        <div class="text-muted small">
                            {d__('flizpay', 'Retrieved from your FLIZ company account and kept up to date automatically.')}
                        </div>
                    </td>
                </tr>
                <tr>
                    <th>{d__('flizpay', 'Order processing')}</th>
                    <td>
                        {d__('flizpay', 'Transfer to Wawi only after payment')}: {if $flizHoldFromWawi}{d__('flizpay', 'yes')}{else}<strong>{d__('flizpay', 'no')}</strong>{/if}
                    </td>
                </tr>
                <tr>
                    <th>{d__('flizpay', 'Plugin version')}</th>
                    <td>{$flizVersion|escape:'html'}</td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="card-footer">
        <form method="post" class="d-inline">
            {$flizTokenInput nofilter}
            <input type="hidden" name="flizAction" value="reconnect">
            <button type="submit" class="btn btn-primary btn-sm">{d__('flizpay', 'Reconnect')}</button>
        </form>
        <form method="post" class="d-inline ml-2">
            {$flizTokenInput nofilter}
            <input type="hidden" name="flizAction" value="refreshCashback">
            <button type="submit" class="btn btn-outline-secondary btn-sm">{d__('flizpay', 'Refresh discount data')}</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">{d__('flizpay', 'Open FLIZpay payments')}</div>
    <div class="card-body p-0">
        {if $flizOpenPayments|@count === 0}
            <p class="text-muted p-3 mb-0">{d__('flizpay', 'There are currently no open FLIZpay payments.')}</p>
        {else}
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>{d__('flizpay', 'Order')}</th>
                            <th>{d__('flizpay', 'Created')}</th>
                            <th>{d__('flizpay', 'Attempt')}</th>
                            <th>{d__('flizpay', 'Transaction')}</th>
                            <th>Status</th>
                            <th>{d__('flizpay', 'Amount')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach $flizOpenPayments as $payment}
                            <tr>
                                <td>{$payment->cBestellNr|escape:'html'}</td>
                                <td>{$payment->dCreated|escape:'html'}</td>
                                <td>{$payment->nAttempt|escape:'html'}</td>
                                <td><code class="small">{$payment->cTransactionId|default:'—'|escape:'html'}</code></td>
                                <td>{$payment->txStatus|default:'—'|escape:'html'}</td>
                                <td>
                                    {if $payment->fOriginalAmount}
                                        {$payment->fOriginalAmount|string_format:"%.2f"} {$payment->cCurrency|escape:'html'}
                                    {else}—{/if}
                                </td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
        {/if}
    </div>
</div>

{if $flizAwaitingTest}
    <script>
        {literal}
        window.setTimeout(function () { window.location.reload(); }, 5000);
        {/literal}
    </script>
{/if}
