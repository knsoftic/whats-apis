@php
    $company = config('legal.company');
    $product = config('legal.product');
    $site = config('legal.website');
    $email = config('legal.email');
@endphp
<x-legal-layout title="Privacy Policy">
    <p>This Privacy Policy explains how <strong>{{ $company }}</strong> (“we”, “us”) collects, uses and protects information when businesses use <strong>{{ $product }}</strong> at <strong>{{ $site }}</strong> (the “Service”) to send and receive messages through the official WhatsApp Business Platform (Cloud API) provided by Meta.</p>

    <h2 id="who">1. Who this policy covers</h2>
    <ul>
        <li><strong>Customers</strong> — businesses and their staff who create an account and connect WhatsApp Business numbers to the Service.</li>
        <li><strong>End users</strong> — people who exchange WhatsApp messages with a Customer’s business number.</li>
    </ul>
    <p>For messaging data, the Customer decides why and how it is used (the Customer is the data controller). We process that data on the Customer’s behalf and according to their instructions (we act as a service provider / processor).</p>

    <h2 id="collect">2. Information we collect</h2>
    <h3>Account information</h3>
    <ul>
        <li>Name, email address and role of Customer staff; passwords are stored only as secure hashes.</li>
        <li>Sign-in records such as time, IP address and browser, used for security and audit logs.</li>
    </ul>
    <h3>WhatsApp Business account information</h3>
    <ul>
        <li>Meta business ID, WhatsApp Business Account (WABA) ID, phone number ID, display phone number, verified business name and quality rating.</li>
        <li>Access tokens and app secrets needed to call the WhatsApp Cloud API. These are encrypted at rest and never shown in full.</li>
        <li>When a business connects through Meta’s Embedded Signup, Meta shares the IDs above and a business access token with the Service after the business grants permission.</li>
    </ul>
    <h3>Messaging data</h3>
    <ul>
        <li>WhatsApp phone numbers and profile names of end users, message content (text, images, video, audio, documents, locations, contact cards, button replies), timestamps and delivery/read statuses.</li>
        <li>Message templates and their approval status.</li>
        <li>Contact details, tags, internal notes and opt-in / opt-out (consent) records that Customers add.</li>
    </ul>
    <h3>Technical information</h3>
    <ul>
        <li>Server logs, error logs and webhook delivery records. Access tokens and secrets are automatically removed from logs.</li>
    </ul>

    <h2 id="use">3. How we use information</h2>
    <ul>
        <li>To provide the Service: connect WhatsApp numbers, receive messages from Meta’s webhooks, send messages that Customers create, and show conversations in real time.</li>
        <li>To keep the Service secure: verifying webhook signatures, access control, preventing abuse and keeping audit logs.</li>
        <li>To provide support, fix problems and improve reliability.</li>
        <li>To meet legal obligations.</li>
    </ul>
    <p>We do <strong>not</strong> sell personal information, use messaging data for advertising, or share it with third parties for their own marketing.</p>

    <h2 id="meta">4. Meta and WhatsApp</h2>
    <p>Messages are sent and received through the WhatsApp Business Platform operated by Meta Platforms, Inc. and WhatsApp LLC. Meta processes this data under its own terms and policies, including the <a href="https://www.whatsapp.com/legal/business-terms" target="_blank" rel="noopener">WhatsApp Business Terms of Service</a>, the <a href="https://www.whatsapp.com/legal/business-policy" target="_blank" rel="noopener">WhatsApp Business Messaging Policy</a> and the <a href="https://www.facebook.com/privacy/policy" target="_blank" rel="noopener">Meta Privacy Policy</a>. We only access the WhatsApp assets and permissions a business explicitly grants to our app.</p>

    <h2 id="consent">5. Consent of end users</h2>
    <p>Customers are responsible for obtaining the opt-in required by WhatsApp and applicable law before sending business-initiated messages. The Service records consent status, and an end user who replies <strong>STOP</strong> or <strong>UNSUBSCRIBE</strong> is marked as opted out of business-initiated messages.</p>

    <h2 id="sharing">6. When we share information</h2>
    <ul>
        <li><strong>Meta / WhatsApp</strong> — to deliver and receive messages.</li>
        <li><strong>Infrastructure providers</strong> — hosting, storage and email delivery (for password resets), bound by confidentiality and security obligations.</li>
        <li><strong>Legal reasons</strong> — when required by law, court order or to protect rights, safety and security.</li>
        <li><strong>Business transfer</strong> — as part of a merger or acquisition, with this policy continuing to apply.</li>
    </ul>

    <h2 id="retention">7. Data retention</h2>
    <ul>
        <li>Conversations, contacts and media are kept while the Customer’s account and connected number remain active, or until the Customer deletes them.</li>
        <li>Raw webhook payloads are deleted automatically after 30 days.</li>
        <li>When a Customer closes their account or disconnects a number and asks for deletion, the related data is deleted within 30 days, unless the law requires us to keep it longer.</li>
    </ul>

    <h2 id="security">8. Security</h2>
    <p>We use HTTPS for all traffic. Access tokens and app secrets are encrypted with AES-256. Webhooks are accepted only with a valid Meta signature. Staff access is role-based, with each user limited to the numbers they are assigned. Activity is recorded in audit logs. No method of transmission or storage is 100% secure, but we work to protect your information and fix issues promptly.</p>

    <h2 id="cookies">9. Cookies</h2>
    <p>The Service uses only essential cookies needed to sign in and to protect forms (session and CSRF cookies). Your light/dark theme choice is stored in your browser’s local storage. We do not use advertising or tracking cookies. When you use “Connect with Meta”, Meta’s Facebook SDK is loaded and Meta’s own cookie policy applies.</p>

    <h2 id="rights">10. Your rights</h2>
    <p>Depending on where you live, including under India’s Digital Personal Data Protection Act, 2023 and the GDPR where applicable, you may have the right to access, correct or delete your personal data, to withdraw consent, and to complain to a data protection authority. End users should contact the business they messaged first, because that business controls their conversation data. We will help Customers respond to such requests.</p>

    <h2 id="data-deletion">11. Data deletion instructions</h2>
    <p>You can request deletion of your data at any time:</p>
    <ul>
        <li><strong>Customers:</strong> delete contacts, conversations or a whole connected WhatsApp number (with all its data) from the dashboard. To delete your entire account, email <a href="mailto:{{ $email }}">{{ $email }}</a>.</li>
        <li><strong>Remove app access in Meta:</strong> in Meta Business Settings → Integrations / Connected apps (or System users), remove {{ $company }}’s access to your WhatsApp Business Account. We then stop receiving data for that account.</li>
        <li><strong>End users:</strong> email <a href="mailto:{{ $email }}?subject=Data%20deletion%20request">{{ $email }}</a> with the subject “Data deletion request”. Include your WhatsApp number and the business you messaged.</li>
    </ul>
    <p>We confirm deletion within 30 days. We may keep minimal records where the law requires it, for example security logs.</p>

    <h2 id="children">12. Children</h2>
    <p>The Service is intended for businesses and is not directed to anyone under 18. We do not knowingly collect personal data from children through our own account sign-up.</p>

    <h2 id="transfers">13. International transfers</h2>
    <p>Data may be processed in countries other than yours, including by Meta and our hosting providers. Where required, we use appropriate safeguards for such transfers.</p>

    <h2 id="changes">14. Changes to this policy</h2>
    <p>We may update this policy from time to time. The “Effective date” above shows the latest version. We will notify Customers of material changes by email or in the dashboard.</p>

    <h2 id="contact">15. Contact</h2>
    <p>{{ $company }}<br>
        Email: <a href="mailto:{{ $email }}">{{ $email }}</a><br>
        @if (config('legal.address')) Address: {{ config('legal.address') }}<br> @endif
        Website: {{ $site }}</p>
</x-legal-layout>
