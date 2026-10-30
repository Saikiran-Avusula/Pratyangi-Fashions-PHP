<?php
declare(strict_types=1);

use Pratyangi\Views\Icons;

// Figma 10: "Add a new credit or debit card". UI ONLY: every field is disabled, nothing can be typed, sent or stored. Only the preview payments page renders it (DEMO_UI=true).
?><div class="pf_dScrim" data-demo-modal hidden><div class="pf_dModal" role="dialog" aria-modal="true" aria-labelledby="pf-card-title">
<header><h2 id="pf-card-title">Add a new credit or debit card</h2><button type="button" class="pf_dClose" aria-label="Close dialog" data-demo-close><?= Icons::svg('x', 22, 2) ?></button></header>
<form class="pf_dCardForm" autocomplete="off" novalidate><div class="pf_dCardBody"><div class="pf_dCardFields">
<label for="pf-card-no">Card number</label><input id="pf-card-no" type="text" disabled autocomplete="off" inputmode="numeric" placeholder="•••• •••• •••• ••••"/>
<label for="pf-card-nick">Nickname</label><input id="pf-card-nick" type="text" disabled autocomplete="off"/>
<label for="pf-card-mm">Expiry date</label><span class="pf_dExpiry"><select id="pf-card-mm" disabled autocomplete="off" aria-label="Expiry month"><option>MM</option></select><select disabled autocomplete="off" aria-label="Expiry year"><option>YYYY</option></select></span></div>
<div class="pf_dCardAside"><p>Please ensure that you enable your card for online payments from your bank’s app.</p><p class="pf_dChips" aria-hidden="true"><i>Visa</i><i>Mastercard</i><i>RuPay</i></p>
<p class="pf_dNote"><?= Icons::svg('lock-keyhole', 16, 1.8) ?>Cards are added securely in the Razorpay payment window.</p></div></div>
<footer><button type="button" class="pf_dBtn" data-demo-close><span>Cancel</span></button><button type="button" class="pf_dBtn" disabled><span>Continue</span><?= Icons::svg('chevron-right', 16, 2) ?></button></footer></form></div></div>
