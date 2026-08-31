{**
 * Backend tab "Status" — connection state, cashback and open payments.
 *}
{foreach $flizMessages as $message}
    <div class="alert alert-{$message.type|escape:'html'}">{$message.text|escape:'html'}</div>
{/foreach}

<div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span>Verbindung zu FLIZpay</span>
        {if $flizConnected}
            <span class="badge badge-success badge-pill">verbunden</span>
        {else}
            <span class="badge badge-danger badge-pill">nicht verbunden</span>
        {/if}
    </div>
    <div class="card-body">
        {if !$flizConnected}
            <div class="alert alert-warning">
                Solange die Verbindung nicht vollständig ist, wird FLIZpay im Checkout <strong>nicht</strong> angeboten.
                {if !$flizApiKeySet}
                    Hinterlege zuerst deinen API-Key unter „Einstellungen“.
                {elseif !$flizWebhookKeySet}
                    Der Webhook-Schlüssel fehlt – bitte die Verbindung neu aufbauen.
                {elseif !$flizWebhookAlive}
                    Warten auf die Test-Benachrichtigung von FLIZpay. Der Shop muss dafür öffentlich erreichbar sein
                    (kein Passwortschutz, keine IP-Sperre, gültiges SSL-Zertifikat).
                {/if}
            </div>
        {/if}

        <table class="table table-sm mb-0">
            <tbody>
                <tr>
                    <th style="width:32%">API-Key</th>
                    <td>{if $flizApiKeySet}hinterlegt{else}<em>nicht hinterlegt</em>{/if}</td>
                </tr>
                <tr>
                    <th>Webhook-Schlüssel</th>
                    <td>{if $flizWebhookKeySet}vorhanden{else}<em>fehlt</em>{/if}</td>
                </tr>
                <tr>
                    <th>Webhook-URL</th>
                    <td>
                        <code>{$flizWebhookUrl|escape:'html'}</code>
                        {if $flizWebhookUrl !== $flizExpectedWebhookUrl}
                            <div class="text-danger small mt-1">
                                Die registrierte URL weicht von der Shop-URL ab
                                (<code>{$flizExpectedWebhookUrl|escape:'html'}</code>).
                                Bitte die Verbindung neu aufbauen.
                            </div>
                        {/if}
                    </td>
                </tr>
                <tr>
                    <th>Test-Benachrichtigung</th>
                    <td>
                        {if $flizWebhookAlive}empfangen{else}<em>ausstehend</em>{/if}
                        {if $flizLastWebhookAt}
                            <span class="text-muted small">(letzte Benachrichtigung: {$flizLastWebhookAt|escape:'html'})</span>
                        {/if}
                    </td>
                </tr>
                <tr>
                    <th>Rabatt / Cashback</th>
                    <td>
                        {if $flizCashback}
                            Erstkauf: {$flizCashback.first_purchase_amount|string_format:"%.2f"} % ·
                            Folgekäufe: {$flizCashback.standard_amount|string_format:"%.2f"} %
                        {else}
                            <em>kein aktiver Rabatt</em>
                        {/if}
                        <div class="text-muted small">
                            Wird aus deinem FLIZ-Firmenkonto übernommen und automatisch aktuell gehalten.
                        </div>
                    </td>
                </tr>
                <tr>
                    <th>Bestellabwicklung</th>
                    <td>
                        Wawi-Übergabe erst nach Zahlung: {if $flizHoldFromWawi}ja{else}<strong>nein</strong>{/if}
                    </td>
                </tr>
                <tr>
                    <th>Plugin-Version</th>
                    <td>{$flizVersion|escape:'html'}</td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="card-footer">
        <form method="post" class="d-inline">
            {$flizTokenInput nofilter}
            <input type="hidden" name="flizAction" value="reconnect">
            <button type="submit" class="btn btn-primary btn-sm">Verbindung neu aufbauen</button>
        </form>
        <form method="post" class="d-inline ml-2">
            {$flizTokenInput nofilter}
            <input type="hidden" name="flizAction" value="refreshCashback">
            <button type="submit" class="btn btn-outline-secondary btn-sm">Rabattdaten aktualisieren</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">Offene FLIZpay-Zahlungen</div>
    <div class="card-body p-0">
        {if $flizOpenPayments|@count === 0}
            <p class="text-muted p-3 mb-0">Aktuell gibt es keine offenen FLIZpay-Zahlungen.</p>
        {else}
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Bestellung</th>
                            <th>Erstellt</th>
                            <th>Versuch</th>
                            <th>Transaktion</th>
                            <th>Status</th>
                            <th>Betrag</th>
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
