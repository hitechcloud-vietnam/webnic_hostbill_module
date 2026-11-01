{literal}
<script type="text/javascript">
    function wbcpickcontact(type, elem) {
        $('.vncontactpicker.picked').removeClass('picked');
        $(elem).addClass('picked');
    }
</script>
{/literal}

<table cellspacing="0" cellpadding="10" border="0" width="100%">
    {if is_array($contacts.registrant)}
        <tbody>
            <tr>
                {foreach from=$contacts item=contact key=ctype}
                    <td width="25%" valign="top">
                        <a class="tchoice vncontactpicker" style="font-size:14px; font-weight:bolder; text-transform:capitalize;">{$ctype}</a>
                        <table cellpadding="2" width="100%">
                            {foreach from=$contact item=line key=name}
                                <tr>
                                    <td align="right" width="120">{if $name == 'firstname'}{$lang.Name}{elseif $lang[$name]}{$lang.$name}{elseif $regfields[$name]}{$regfields[$name].name}{else}{$name|capitalize}{/if}</td>
                                    <td>
                                        {if $name === 'phonenumber' || $name == 'phone'}
                                            <input type="text" readonly size="30" value="{$line|escape}" {if $line == "disabled"}disabled="disabled"{/if} id="field_domain_{$ctype}_{$name}" data-initial-country="{$contact.country|default:$default_country}"/>
                                            <script>initPhoneNumberField($('#field_domain_{$ctype}_{$name}'));</script>
                                        {else}
                                            <input type="text" readonly size="30" value="{$line|escape}" {if $line == "disabled"}disabled="disabled"{/if}/>
                                        {/if}
                                    </td>
                                </tr>
                            {/foreach}
                        </table>
                    </td>
                {/foreach}
            </tr>
        </tbody>
    {else}
        <tbody>
            <tr>
                <td width="30%" valign="top">
                    <table cellpadding="2" width="100%">
                        {foreach from=$contacts item=line key=name}
                            <tr>
                                <td align="right" width="120">{if $name == 'firstname'}{$lang.Name}{elseif $lang[$name]}{$lang.$name}{elseif $regfields[$name]}{$regfields[$name].name}{else}{$name|capitalize}{/if}</td>
                                <td>
                                    {if $name === 'phonenumber' || $name == 'phone'}
                                        <input type="text" size="30" value="{$line|escape}" name="updateContactInfo[{$name}]" {if $line == "disabled"}disabled="disabled"{/if} id="field_domain_{$name}" data-initial-country="{$contact.country|default:$default_country}"/>
                                        <script>initPhoneNumberField($('#field_domain_{$name}'));</script>
                                    {else}
                                        <input type="text" size="30" value="{$line|escape}" name="updateContactInfo[{$name}]" {if $line == "disabled"}disabled="disabled"{/if}/>
                                    {/if}
                                </td>
                            </tr>
                        {/foreach}
                    </table>
                </td>
            </tr>
        </tbody>
    {/if}
</table>
