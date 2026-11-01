<table border="0" cellpadding="6" cellspacing="0" width="100%" style="margin-bottom:10px;">
    {if $server_fields.display.username}<tr><td align="right" width="165"><strong>{if $server_fields.description.username}{$server_fields.description.username}{else}{$lang.Username}{/if}</strong></td><td><input name="username" size="25" value="{$server.username}" class="inp"/></td></tr>{/if}
    {if $server_fields.display.password}<tr><td align="right" width="165"><strong>{if $server_fields.description.password}{$server_fields.description.password}{else}{$lang.Password}{/if}</strong></td><td><input type="password" name="password" size="25" class="inp" value="{$server.password}" autocomplete="off"/></td></tr>{/if}
</table>
<div class="sectionhead">WebNIC DNS Settings</div>
<table border="0" cellpadding="6" cellspacing="0" width="100%" style="margin-bottom:10px;">
    <tr>
        <td align="right" width="165"><strong>Market</strong> <a class="vtip_description" title="WebNIC connect market for this app. Credentials are shared across markets; the market only gates market-specific defaults/behaviour (currently cosmetic for DNS, reserved for future VN-only tweaks so the whole WebNIC suite stays consistent)."></a></td>
        <td>
            <select name="custom[market]" id="market" class="inp">
                <option {if !$server.custom.market || $server.custom.market=='vn'}selected="selected"{/if} value="vn">Vietnam (VN)</option>
                <option {if $server.custom.market=='global'}selected="selected"{/if} value="global">Global</option>
            </select>
        </td>
    </tr>
    <tr>
        <td align="right" width="165"><strong>OTE Environment</strong> <a class="vtip_description" title="Check to use the WebNIC OTE/TEST API (oteapi.webnic.cc). Leave unchecked for LIVE (api.webnic.cc)."></a></td>
        <td><input type="checkbox" name="custom[ote]" id="ote" value="1" {if $server.custom.ote=='1'}checked="checked"{/if}/> Use WebNIC TEST (OTE) environment</td>
    </tr>
    <tr>
        <td align="right" width="165"><strong>Default DNS Tier</strong> <a class="vtip_description" title="Basic = free DNS bundled with a domain (basic zone-record endpoints). Premium = paid DNS subscription (subscription zone-record endpoints). Auto = detect per zone via the subscription lookup."></a></td>
        <td>
            <select name="custom[dns_tier]" id="dns_tier" class="inp">
                <option {if !$server.custom.dns_tier || $server.custom.dns_tier=='auto'}selected="selected"{/if} value="auto">Auto-detect (subscription lookup)</option>
                <option {if $server.custom.dns_tier=='basic'}selected="selected"{/if} value="basic">Basic DNS (free)</option>
                <option {if $server.custom.dns_tier=='premium'}selected="selected"{/if} value="premium">Premium DNS (subscription)</option>
            </select>
        </td>
    </tr>
    <tr>
        <td align="right" width="165"><strong>Default Nameservers</strong> <a class="vtip_description" title="Comma-separated nameservers assigned to new zones (optional; WebNIC assigns platform nameservers by default)."></a></td>
        <td><input type="text" name="custom[default_ns]" size="40" value="{$server.custom.default_ns}" class="inp"/></td>
    </tr>
</table>
