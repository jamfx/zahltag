<?php
declare(strict_types=1);
$cleanupDays = (int)setting('cleanup_days', 90);
$maxReceiptMb = max(1, (int)setting('max_receipt_size_mb', 5));
?>
<div style="max-width:760px;margin-inline:auto;">

    <h1 style="margin-bottom:.5rem;">
        <i class="fa-solid fa-circle-question" aria-hidden="true" style="color:var(--color-primary);margin-right:.4rem;"></i>
        Guide &amp; FAQ
    </h1>
    <p class="text-muted" style="margin-bottom:.5rem;">
        How Zahltag works – for <strong>group admins</strong> (who create and manage a group) and
        <strong>members</strong> (who log expenses and split the bill).
    </p>
    <p class="text-muted text-sm" style="margin-bottom:1.75rem;">
        No account required, no app to install – everything runs through a link in your browser.
    </p>

    <!-- ============================================================ -->
    <!-- Basic principle                                               -->
    <!-- ============================================================ -->
    <div class="card" style="margin-bottom:1.5rem;">
        <h2 style="font-size:1.125rem;">
            <i class="fa-solid fa-lightbulb" aria-hidden="true" style="color:var(--color-primary);"></i>
            Basic principle
        </h2>
        <p style="margin-bottom:1rem;">
            Every group (flatshare, trip, event …) has a shared list of expenses. Members log who paid for what and
            how it should be split – no registration needed. Zahltag then calculates who still owes whom, using as
            few settlement payments as possible.
        </p>
        <div style="overflow-x:auto">
            <table class="table">
                <thead>
                    <tr><th>Role</th><th>What they can do</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td><i class="fa-solid fa-user-pen" aria-hidden="true"></i> Group admin</td>
                        <td>Creates the group, manages members and settings, exports PDF/CSV</td>
                    </tr>
                    <tr>
                        <td><i class="fa-solid fa-user-group" aria-hidden="true"></i> Member</td>
                        <td>Logs expenses, views the settlement, marks payments as paid/received</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- FAQ                                                           -->
    <!-- ============================================================ -->
    <h2 style="font-size:1.25rem;margin-bottom:.875rem;">
        <i class="fa-solid fa-circle-question" aria-hidden="true" style="color:var(--color-primary);"></i>
        Frequently asked questions
    </h2>

    <?php
    $faq = [
        [
            'q' => 'Do I need to register to take part?',
            'a' => 'No. Neither group admins nor members need an account. Members join with their name, optionally protected by a PIN (see below).',
        ],
        [
            'q' => 'I lost my group\'s management link – what now?',
            'a' => 'Since there is no login, the management link cannot be reset or re-sent if it gets lost. Keep it somewhere safe (e.g. as a bookmark).',
        ],
        [
            'q' => 'Can other members see my payment details (IBAN, PayPal …)?',
            'a' => 'Payment details are only visible to active members of the same group – needed so settlement payments can be made via GiroCode, PayPal or Wero. They are not visible without group access.',
        ],
        [
            'q' => 'What if I made a mistake or want to delete an expense?',
            'a' => 'Any member can edit or delete any expense at any time – there is no third-party approval step. In shared groups, it\'s a good idea to briefly coordinate changes with the group.',
        ],
        [
            'q' => 'Can I log expenses in a different currency?',
            'a' => 'If the instance operator has enabled multi-currency support: yes. The amount is automatically converted to the group\'s currency using the current exchange rate.',
        ],
        [
            'q' => 'How long does my group stay online?',
            'a' => "Indefinitely, as long as it's actively used. Archived groups and empty groups with no expenses are automatically deleted after currently {$cleanupDays}&nbsp;days (configurable by the instance operator).",
        ],
        [
            'q' => 'Can I print the settlement or take it offline?',
            'a' => 'Yes, via the PDF export in the group management area – with a cover page, expense table, balances, suggested payments (including GiroCode) and all uploaded receipts. Alternatively as CSV for further processing in a spreadsheet.',
        ],
        [
            'q' => 'Is the group password-protected?',
            'a' => 'Access works through a long, random link instead of a password. In addition, the group admin can require a PIN for members (see "Settings in detail").',
        ],
        [
            'q' => 'How large can receipts be?',
            'a' => "Photos or PDFs up to {$maxReceiptMb}&nbsp;MB per receipt (configurable by the instance operator).",
        ],
    ];
    ?>
    <div style="margin-bottom:1.75rem;">
        <?php foreach ($faq as $item): ?>
        <details class="card" style="padding:0;margin-bottom:.625rem;">
            <summary style="padding:.875rem 1.125rem;cursor:pointer;font-weight:600;list-style:none;display:flex;align-items:center;gap:.5rem;">
                <?= $item['q'] /* hardcoded in code, no user input */ ?>
                <i class="fa-solid fa-chevron-down" aria-hidden="true" style="margin-left:auto;font-size:.75rem;color:var(--color-text-muted);"></i>
            </summary>
            <div style="padding:0 1.125rem 1.125rem;color:var(--color-text-muted);">
                <?= $item['a'] ?>
            </div>
        </details>
        <?php endforeach; ?>
    </div>

    <!-- ============================================================ -->
    <!-- For group admins                                              -->
    <!-- ============================================================ -->
    <h2 style="font-size:1.25rem;margin-bottom:.875rem;">
        <i class="fa-solid fa-user-pen" aria-hidden="true" style="color:var(--color-primary);"></i>
        For group admins
    </h2>

    <div class="card" style="margin-bottom:1rem;">
        <h3><i class="fa-solid fa-people-group" aria-hidden="true"></i> Creating a group</h3>
        <ol style="padding-left:1.25rem;line-height:1.75;">
            <li>Create a new group on the homepage.</li>
            <li>Required: <strong>group name</strong>. If multi-currency is enabled, you can also pick the group's currency.</li>
            <li>The group is created immediately after submitting – there is no login. You're taken straight to the management page instead.</li>
        </ol>
    </div>

    <div class="card" style="margin-bottom:1rem;">
        <h3><i class="fa-solid fa-link" aria-hidden="true"></i> The two links: management vs. member view</h3>
        <p style="margin-bottom:.75rem;">After creating a group (and any time from the management page), two different links are shown:</p>
        <ul style="padding-left:1.25rem;line-height:1.75;margin-bottom:.75rem;">
            <li><strong>Management link</strong> (<code>/manage/…</code>) – for the group admin. Used to manage members, change settings, and export PDF/CSV. <strong>Don't share this link with members!</strong></li>
            <li><strong>Share link</strong> (<code>/group/…</code>) – the link sent to all members (e.g. via WhatsApp, email or QR code). Members use it to log expenses and view the settlement.</li>
        </ul>
        <div style="padding:.75rem 1rem;background:var(--color-primary-light);border-radius:var(--radius);">
            <strong><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Important:</strong>
            Both links contain a long, random token instead of a login. Anyone who knows the management link can manage the group – treat it as confidential. Bookmarking it is recommended, since there is no password-reset process.
        </div>
    </div>

    <div class="card" style="margin-bottom:1rem;">
        <h3><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Inviting members</h3>
        <p style="margin-bottom:0;">
            The share link can be copied and shared directly, or sent by email to one or more addresses
            (comma-separated) right from the management page.
        </p>
    </div>

    <div class="card" style="margin-bottom:1rem;">
        <h3><i class="fa-solid fa-users-gear" aria-hidden="true"></i> Managing members</h3>
        <p style="margin-bottom:.5rem;">On the members page, you can set per member:</p>
        <ul style="padding-left:1.25rem;line-height:1.75;margin-bottom:.75rem;">
            <li><strong>Deactivate</strong> – the member can no longer log in, but stays visible on existing expenses.</li>
            <li><strong>Reset PIN</strong> – if a member forgot their PIN.</li>
            <li><strong>Default weight</strong> – the share this member automatically gets on new expenses in "Weighted" mode (1&nbsp;= normal, e.g. 2 for a double share or 0.5 for a half share).</li>
        </ul>
    </div>

    <div class="card" style="margin-bottom:1rem;">
        <h3><i class="fa-solid fa-sliders" aria-hidden="true"></i> Settings in detail</h3>
        <div style="overflow-x:auto">
            <table class="table">
                <thead><tr><th>Setting</th><th>Meaning</th></tr></thead>
                <tbody>
                    <tr>
                        <td>PIN required</td>
                        <td>If enabled, a 4–12 character PIN must be set on first joining, and is asked for again when logging in elsewhere (e.g. on another device). Disabling it clears all previously set PINs.</td>
                    </tr>
                    <tr>
                        <td>Categories</td>
                        <td>Lets you categorize expenses (e.g. accommodation, food, transport). Can be optional or required; preset categories can be shown/hidden, and custom categories can be added and reordered freely.</td>
                    </tr>
                    <tr>
                        <td>PDF margins</td>
                        <td>Overrides the instance-wide default page margins of the settlement PDF for this group.</td>
                    </tr>
                    <tr>
                        <td>Archive</td>
                        <td>Marks the group as finished. Archived groups are automatically deleted after the configured retention period (see FAQ above).</td>
                    </tr>
                    <tr>
                        <td>Delete</td>
                        <td>Deletes the group immediately and irreversibly, including all expenses and receipts. Requires confirmation by re-typing the group name.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" style="margin-bottom:1.75rem;">
        <h3><i class="fa-solid fa-file-export" aria-hidden="true"></i> Exporting as PDF or CSV</h3>
        <p style="margin-bottom:.5rem;">The management page lets you export the full settlement:</p>
        <ul style="padding-left:1.25rem;line-height:1.75;margin-bottom:0;">
            <li>The <strong>PDF statement</strong> includes a cover page, expense table, balances, suggested payments with GiroCode (for EUR groups), and all uploaded receipts – photos are embedded as image pages, PDF receipts are merged in page-for-page.</li>
            <li>The <strong>CSV file</strong> contains all expenses for further processing in a spreadsheet.</li>
        </ul>
    </div>

    <!-- ============================================================ -->
    <!-- For members                                                   -->
    <!-- ============================================================ -->
    <h2 style="font-size:1.25rem;margin-bottom:.875rem;">
        <i class="fa-solid fa-user-group" aria-hidden="true" style="color:var(--color-primary);"></i>
        For members
    </h2>

    <div class="card" style="margin-bottom:1rem;">
        <h3><i class="fa-solid fa-hand-pointer" aria-hidden="true"></i> Joining a group</h3>
        <ol style="padding-left:1.25rem;line-height:1.75;margin-bottom:.75rem;">
            <li>Open the <strong>share link</strong> received from the group admin.</li>
            <li>Enter your name – if PIN protection is enabled, also set a PIN (4–12 characters).</li>
            <li>When returning later (e.g. on a different device), pick your name from the list and log in with your PIN.</li>
        </ol>
        <p class="text-muted text-sm" style="margin-bottom:0;">
            No registration, no email address needed. The login is browser-based for the session.
        </p>
    </div>

    <div class="card" style="margin-bottom:1rem;">
        <h3><i class="fa-solid fa-receipt" aria-hidden="true"></i> Logging an expense</h3>
        <p style="margin-bottom:.5rem;">When logging an expense, you set:</p>
        <ul style="padding-left:1.25rem;line-height:1.75;margin-bottom:.75rem;">
            <li><strong>Description, amount, date</strong> and who paid.</li>
            <li><strong>Split</strong> – three modes are available:</li>
        </ul>
        <div style="overflow-x:auto;margin-bottom:.75rem;">
            <table class="table">
                <thead><tr><th>Mode</th><th>Meaning</th></tr></thead>
                <tbody>
                    <tr><td>Equal</td><td>The amount is split evenly across all selected members.</td></tr>
                    <tr><td>Weighted</td><td>Split according to each member's individual weight (e.g. children count as half).</td></tr>
                    <tr><td>Custom</td><td>Each member's amount is entered by hand.</td></tr>
                </tbody>
            </table>
        </div>
        <p style="margin-bottom:0;">
            Optionally, you can attach a <strong>receipt</strong> (photo or PDF) and – if enabled – a
            <strong>category</strong>. Any expense can later be edited or deleted by any member.
        </p>
    </div>

    <div class="card" style="margin-bottom:1rem;">
        <h3><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i> Understanding the settlement</h3>
        <p style="margin-bottom:0;">
            The settlement page shows each member's balance (paid minus their share) and a list of concrete
            settlement payments – calculated using an algorithm that minimizes the number of transfers needed,
            instead of everyone settling up individually with everyone else.
        </p>
    </div>

    <div class="card" style="margin-bottom:1rem;">
        <h3><i class="fa-solid fa-money-bill-transfer" aria-hidden="true"></i> Marking &amp; confirming payments</h3>
        <p style="margin-bottom:.5rem;">Settling a payment works in two steps:</p>
        <ol style="padding-left:1.25rem;line-height:1.75;margin-bottom:0;">
            <li>Whoever paid marks the payment as <strong>"paid"</strong> in the settlement.</li>
            <li>The recipient confirms receipt – only then does the payment count as <strong>"confirmed"</strong>
                in the settlement and factor into further calculations.</li>
        </ol>
    </div>

    <div class="card" style="margin-bottom:1rem;">
        <h3><i class="fa-solid fa-qrcode" aria-hidden="true"></i> Paying via GiroCode, PayPal or Wero</h3>
        <p style="margin-bottom:0;">
            Every member can save an IBAN (for the SEPA GiroCode QR code, EUR groups only), a PayPal link and a Wero
            link in their own payment details. Other members see these details next to suggested payments and can
            pay directly via QR code or link.
        </p>
    </div>

    <!-- ============================================================ -->
    <!-- Privacy & Security                                            -->
    <!-- ============================================================ -->
    <div class="card" style="margin-bottom:1rem;">
        <h2 style="font-size:1.125rem;">
            <i class="fa-solid fa-shield-halved" aria-hidden="true" style="color:var(--color-primary);"></i>
            Privacy &amp; Security
        </h2>
        <ul style="padding-left:1.25rem;line-height:1.8;margin-bottom:0;">
            <li><strong>No registration</strong> is required, neither for group admins nor for members.</li>
            <li>Access works through long, random links (tokens) instead of user accounts. Anyone who knows a link has access to that view – don't share links publicly.</li>
            <li>Receipt photos and PDFs are not directly accessible over the internet, only to logged-in members of the respective group.</li>
            <li>Payment details (IBAN, PayPal, Wero) are only visible to active members of the same group.</li>
            <li>Archived and empty groups are automatically deleted, including all data, once the retention period expires.</li>
        </ul>
    </div>

</div>
