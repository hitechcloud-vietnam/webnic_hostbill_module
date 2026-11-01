<tr>
    <td></td>
    <td>
        {literal}
        <style>
            .sectionbody > div{
                padding:3px;
            }
            input[type=text].inp{
                width:250px; height: 17px;
            }
            select.inp{
                width:256px
            }
        </style>
        <script type="text/javascript">
                $(function () {
                    var select = $('#dnstpl_select'),
                        val = select.val();

                    select.children(':first-child').nextAll().remove();
                    $('#dns_list li[id^=tpl_]').filter(function () {
                        return !$(this).attr('id').match('tpl_n')
                    }).each(function () {
                        var tpl = $(this),
                            id = tpl.attr('id').replace('tpl_', ''),
                            name = tpl.find('.template_name span').text();
                        select.append('<option value="' + id + '">' + name + '</option>');
                    });
                    select.val(val);
                });
            </script>
        {/literal}
        <div class="left" style="width:330px;">
            <div style="margin:0 5px 5px 0">
                <div class="sectionhead_ext open">Account Settings</div>
                <div class="sectionbody" style="padding:10px;">
                    <div>
                        <b class="fs11">Use this template with each new zone</b> <a class="vtip_description" title="Select dns template that will be applied to each new zone created under this package. You can define DNS Templates in <b>Components</b> tab"></a><br/>
                        <select id="dnstpl_select" name="options[dns_template]" class="inp" style="width: 255px">
                            <option value="">- {$lang.none} -</option>
                            {if $default.dns_template}<option selected="selected" value="{$default.dns_template}">Loading..</option>{/if}
                        </select>
                    </div>
                    <div>
                        <b class="fs11">Max domains count: <a class="vtip_description" title="Maximum number of domains client can create through clientarea interface"></a></b><br/>
                        <input type="text" name="options[maxdomain]" value="{$default.maxdomain}" class="inp" style="width:250px;"/>
                    </div>
                    <div>
                        <b class="fs11">DNS tier for this product</b> <a class="vtip_description" title="Auto = detect premium subscription per zone, else basic. Basic = always use free basic DNS endpoints. Premium = always use subscription DNS endpoints."></a><br/>
                        <select name="options[dns_tier]" class="inp" style="width:255px">
                            <option value="" {if !$default.dns_tier}selected="selected"{/if}>- Use app default -</option>
                            <option value="auto" {if $default.dns_tier=='auto'}selected="selected"{/if}>Auto-detect</option>
                            <option value="basic" {if $default.dns_tier=='basic'}selected="selected"{/if}>Basic DNS (free)</option>
                            <option value="premium" {if $default.dns_tier=='premium'}selected="selected"{/if}>Premium DNS (subscription)</option>
                        </select>
                    </div>
                    <div>
                        <input type="checkbox" name="options[auto_subscribe]" value="1" {if $default.auto_subscribe == '1'}checked{/if}>
                        <b class="fs11">Auto-subscribe premium DNS on zone create</b>
                    </div>
                    <div>
                        <input type="checkbox" name="options[hide_billing]" value="1" {if $default.hide_billing == '1'}checked{/if}>
                        <b class="fs11">Hide billing info from DNS product</b>
                    </div>
                    <div>
                        <input type="checkbox" name="options[hide_zone_management]" value="1" {if $default.hide_zone_management == '1'}checked{/if}>
                        <b class="fs11">Hide ability to add new zones manually</b>
                    </div>
                </div>
            </div>

            <div style="margin:0 5px 5px 0">
                <div class="sectionhead_ext open">Nameserver &amp; SOA Settings</div>
                <div class="sectionbody" style="padding:10px;">
                    <div>
                        <b class="fs11">SOA Email address</b><br/>
                        <input type="radio" name="options[soaemailclient]" value="1" {if $default.soaemailclient=='1' || !$default.soaemailclient}checked="checked"{/if} /> Use client email address <br />
                        <input type="radio" name="options[soaemailclient]" value="0" {if $default.soaemailclient && $default.soaemailclient!='1'}checked="checked"{/if} /> Use email address below:
                        <input type="text" name="options[soaemaildefault]" value="{$default.soaemaildefault}" class="inp" style="width:250px;"/>
                    </div>
                    <div>
                        <b class="fs11">First nameserver:</b><br/>
                        <input type="text" name="options[ns1]" value="{$default.ns1}" class="inp" style="width:250px;"/>
                    </div>
                    <div>
                        <b class="fs11">Second nameserver:</b><br/>
                        <input type="text" name="options[ns2]" value="{$default.ns2}" class="inp" style="width:250px;"/>
                    </div>
                    <div>
                        <b class="fs11">Third nameserver:</b><br/>
                        <input type="text" name="options[ns3]" value="{$default.ns3}" class="inp" style="width:250px;"/>
                    </div>
                    <div>
                        <b class="fs11">Fourth nameserver:</b><br/>
                        <input type="text" name="options[ns4]" value="{$default.ns4}" class="inp" style="width:250px;"/>
                    </div>
                    <div>
                        <b class="fs11">Default record TTL</b><br/>
                        <input type="text" name="options[soattldefault]" value="{if $default.soattldefault!=''}{$default.soattldefault}{else}3600{/if}" class="inp" style="width:80px;"/>
                    </div>
                </div>
            </div>
        </div>
    </td>
</tr>
