<div class="wbox">
    <div class="wbox_header">
        {assign var=widg_name value="`$widget.name`_widget"}
        {if $lang[$widg_name]}{$lang[$widg_name]}
        {elseif $lang[$widget.name]}{$lang[$widget.name]}
        {elseif $widget.fullname}{$widget.fullname}
        {else}WebNIC Account Balance
        {/if}
    </div>
    <div class="wbox_content">
        {if $webnic_domain_balance_error}
            <div class="alert alert-danger">{$webnic_domain_balance_error|escape}</div>
        {/if}

        {if $webnic_domain_balance_rows}
            <table cellspacing="0" cellpadding="0" border="0" width="100%" class="checker table table-striped">
                {foreach from=$webnic_domain_balance_rows key=currency item=amount}
                    <tr>
                        <td width="40%"><strong>{$currency|escape}</strong></td>
                        <td>{$amount|escape}</td>
                    </tr>
                {/foreach}
            </table>
        {elseif !$webnic_domain_balance_error}
            <p>Check the current WebNIC reseller account balance.</p>
        {/if}

        <form method="post" action="" style="margin:10px 0 0; text-align:center;">
            <input type="hidden" name="widgetdo" value="webnicDomainBalance" />
            <button type="submit" class="padded btn btn-primary">Check balance</button>
            {securitytoken}
        </form>
    </div>
</div>
