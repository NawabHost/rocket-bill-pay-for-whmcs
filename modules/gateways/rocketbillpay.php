<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function rocketbillpay_MetaData()
{
    return [
        'DisplayName'                => 'Rocket Bill Pay',
        'APIVersion'                 => '1.1',
        'DisableLocalCredtCardInput' => true,
        'TokenisedStorage'           => false,
    ];
}

function rocketbillpay_config()
{
    return [
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => 'Rocket Bill Pay',
        ],
        'shortcode'    => [
            'FriendlyName' => 'Shortcode',
            'Type'         => 'text',
            'Size'         => '25',
            'Default'      => '',
            'Description'  => 'Enter the Biller ID.',
        ],
        'userid'       => [
            'FriendlyName' => 'User ID',
            'Type'         => 'text',
            'Size'         => '25',
            'Default'      => '',
            'Description'  => 'Enter your api user id here',
        ],
        'password'     => [
            'FriendlyName' => 'Password',
            'Type'         => 'password',
            'Size'         => '25',
            'Default'      => '',
            'Description'  => 'Enter the api password here',
        ],
        'authUser'     => [
            'FriendlyName' => 'Auth Username',
            'Type'         => 'password',
            'Size'         => '25',
            'Description'  => 'API Auth user to access the API.',
        ],
        'authPassword' => [
            'FriendlyName' => 'Auth Password',
            'Type'         => 'password',
            'Size'         => '25',
            'Description'  => 'API Auth password to access the API.',
        ],
        'opcode'       => [
            'FriendlyName' => 'OP Code',
            'Type'         => 'text',
            'Size'         => '25',
            'Default'      => 'GT',
            'Description'  => 'Enter the OP code here.',
        ],
        'fee'          => [
            'FriendlyName' => 'Gateway Fee',
            'Type'         => 'text',
            'Size'         => '25',
            'Default'      => 1.85,
            'Description'  => 'Enter the gateway fee in percentage.',
        ],
        'sandbox'      => [
            'FriendlyName' => 'Sandbox',
            'Type'         => 'yesno',
            'Description'  => 'Tick to enable sandbox mode',
        ],
    ];
}

function rocketbillpay_getTotalPayable($params)
{
    $fee = empty($params['fee']) ? 0 : (($params['fee'] / 100) * $params['amount']);

    return ceil($params['amount'] + $fee);
}

function rocketbillpay_link($params)
{
    $totalDue = rocketbillpay_getTotalPayable($params);
    $action   = $params['systemurl'] . 'modules/gateways/callback/' . $params['paymentmethod'] . '.php';
    $scripts  = rocketbillpay_scriptsHandle($params);

    return <<<HTML
<ol class="text-left margin-top-5">
    <li>Dial <span class="label label-primary">*322#</span> to open Rocket menu.</li>
    <li>Select <span class="label label-primary">Payment</span> option.</li>
    <li>Select <span class="label label-primary">Bill Pay</span></li></li>
    <li>Select <span class="label label-primary">Self</span></li>
    <li>Enter Biller ID <span class="label label-primary">{$params['shortcode']}</span></li>
    <li>Enter Invoice ID <span class="label label-primary">{$params['invoiceid']}</span> as Reference</li>
    <li>Select <span class="label label-primary">{$totalDue}</span> Taka</li>
    <li>Enter PIN and Confirm</li>
</ol>
<form id="rocketbillpay-form" action="$action" method="POST" class="form-inline">
    <input type="hidden" name="id" value="{$params['invoiceid']}">
    <div class="form-group">
        <label class="sr-only" for="inlineFormInput">Transaction Key</label>
        <input type="text" name="txnid" class="form-control mb-2" id="rocketbillpay-trxid" placeholder="123456789321" required>
    </div>
    <button type="submit" id="rocketbillpay-btn" class="btn btn-primary mb-2"><i class="fas fa-circle-notch fa-spin hidden" style="margin-right: 5px"></i>Verify</button>
</form>
<div id="rocketbillpay-response" class="alert alert-danger hidden" style="margin-top: 20px"></div>
{$scripts}
HTML;
}

function rocketbillpay_scriptsHandle($params)
{
    $apiUrl = $params['systemurl'] . 'modules/gateways/callback/' . $params['paymentmethod'] . '.php';
    $markup = <<<HTML
<script>
    window.addEventListener('load', function() {
        var rocketBtn = $('#rocketbillpay-btn');       
        var rocketResponse = $('#rocketbillpay-response');
        var rocketLoader = $('i', rocketBtn);
    
        $('#rocketbillpay-form').on('submit', function(e) {
            e.preventDefault();
            
            rocketBtn.attr('disabled', 'disabled');
            rocketLoader.removeClass('hidden');
    
            $.ajax({
                method: "POST",
                url: "{$apiUrl}",
                data: $('#rocketbillpay-form').serialize()
            }).done(function(response) {
                if (response.status === 'success') {
                    window.location = "{$params['returnurl']}" + "&paymentsuccess=true";
                } else {
                   rocketResponse.removeClass('hidden');
                   rocketResponse.text(response.message);   
                }
            }).fail(function() {
                rocketResponse.removeClass('hidden');
                rocketResponse.text('Something is wrong! Please contact support.');
              }).always(function () {
                rocketBtn.removeAttr('disabled');
                rocketLoader.addClass('hidden');
            });
        })
    });
</script>
HTML;

    return $markup;
}
