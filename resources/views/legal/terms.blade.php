@php
    $company = config('legal.company');
    $product = config('legal.product');
    $site = config('legal.website');
    $email = config('legal.email');
    $jurisdiction = config('legal.jurisdiction');
@endphp
<x-legal-layout title="Terms & Conditions">
    <p>These Terms &amp; Conditions (“Terms”) govern your use of <strong>{{ $product }}</strong> at <strong>{{ $site }}</strong> (the “Service”), provided by <strong>{{ $company }}</strong> (“we”, “us”). By creating an account, connecting a WhatsApp number or using the Service, you agree to these Terms on behalf of yourself and the business you represent (“you”, the “Customer”).</p>

    <h2 id="service">1. The Service</h2>
    <p>The Service is software for managing official WhatsApp Business Platform (Cloud API) numbers. With it you can connect numbers through Meta’s Embedded Signup or by entering credentials manually, receive and send messages, manage contacts and templates, and work as a team. The Service uses Meta’s official APIs only. It does not use WhatsApp Web automation or unofficial clients.</p>
    <p>{{ $company }} is independent and is not affiliated with, endorsed by or sponsored by Meta Platforms, Inc. or WhatsApp LLC. “WhatsApp” and “Meta” are trademarks of their respective owners.</p>

    <h2 id="accounts">2. Accounts and security</h2>
    <ul>
        <li>You must give accurate information and keep your login details confidential.</li>
        <li>You are responsible for everything done under your account, including actions by users you invite, and for assigning them suitable roles.</li>
        <li>Tell us right away at <a href="mailto:{{ $email }}">{{ $email }}</a> if you suspect unauthorized access.</li>
        <li>You must be at least 18 and authorized to bind your business to these Terms.</li>
    </ul>

    <h2 id="whatsapp">3. WhatsApp and Meta requirements</h2>
    <ul>
        <li>You must comply with the <a href="https://www.whatsapp.com/legal/business-terms" target="_blank" rel="noopener">WhatsApp Business Terms of Service</a>, the <a href="https://business.whatsapp.com/policy" target="_blank" rel="noopener">WhatsApp Business Messaging Policy</a>, the WhatsApp Commerce Policy and Meta’s platform terms.</li>
        <li>You must obtain valid opt-in from people before sending them business-initiated messages, and honour opt-out requests.</li>
        <li>Free-form messages may only be sent within the customer service window. Outside that window you may only send message templates approved by Meta. The Service enforces these rules and you must not try to bypass them.</li>
        <li>Meta decides on template approval, messaging limits, quality ratings and account restrictions. We are not responsible for Meta’s decisions, outages or changes to the WhatsApp Business Platform.</li>
        <li>WhatsApp message charges are billed by Meta to the payment method you add in WhatsApp Manager, unless we agree otherwise in writing.</li>
    </ul>

    <h2 id="acceptable-use">4. Acceptable use</h2>
    <p>You must not use the Service to:</p>
    <ul>
        <li>send spam, unsolicited bulk messages, or messages to people who have not opted in;</li>
        <li>send unlawful, fraudulent, deceptive, harassing, hateful or infringing content, or promote goods and services prohibited by WhatsApp’s policies;</li>
        <li>distribute malware, phish for credentials, or collect sensitive data such as full payment card numbers or passwords through chat;</li>
        <li>break, overload, reverse-engineer or bypass the security or rate limits of the Service or of Meta’s APIs;</li>
        <li>resell or give access to the Service to third parties without our written permission.</li>
    </ul>

    <h2 id="data">5. Your data</h2>
    <ul>
        <li>You keep all rights to the messages, contacts, media and other data you process through the Service (“Customer Data”). You are the controller of Customer Data and are responsible for having a lawful basis to process it.</li>
        <li>We process Customer Data only to provide and secure the Service, as described in our <a href="{{ route('legal.privacy') }}">Privacy Policy</a>.</li>
        <li>You can delete Customer Data in the dashboard or ask us to delete it, as described in the Privacy Policy.</li>
    </ul>

    <h2 id="fees">6. Fees</h2>
    <p>Fees for the Service, if any, are those in your order, plan or invoice. Fees are payable in advance and are not refundable except where the law requires it. Taxes are extra. Meta’s WhatsApp charges are separate from our fees.</p>

    <h2 id="availability">7. Availability and changes</h2>
    <p>We aim to keep the Service available and secure, but we do not guarantee uninterrupted or error-free operation unless we agree to a service level in writing. We may update or change features. We will try to give notice of changes that significantly reduce functionality.</p>

    <h2 id="ip">8. Intellectual property</h2>
    <p>The Service, its software, design and branding belong to {{ $company }} and its licensors. We give you a limited, non-exclusive, non-transferable right to use the Service during your subscription and under these Terms. Feedback you give us may be used without obligation to you.</p>

    <h2 id="suspension">9. Suspension and termination</h2>
    <ul>
        <li>You may stop using the Service at any time and ask us to close your account.</li>
        <li>We may suspend or terminate access if you breach these Terms or WhatsApp’s policies, if your use creates a security or legal risk, if fees remain unpaid, or if Meta restricts your account.</li>
        <li>After termination, your Customer Data is deleted as described in the Privacy Policy. You can export contacts before closing your account.</li>
    </ul>

    <h2 id="disclaimer">10. Disclaimers</h2>
    <p>The Service is provided “as is” and “as available”. To the extent the law allows, we disclaim all implied warranties, including merchantability, fitness for a particular purpose and non-infringement. We do not guarantee that any message will be delivered, read or replied to.</p>

    <h2 id="liability">11. Limitation of liability</h2>
    <p>To the extent the law allows, {{ $company }} is not liable for any indirect, incidental, special, consequential or punitive damages, or for loss of profits, revenue, data or goodwill. Our total liability for any claim relating to the Service is limited to the amount you paid us for the Service in the 3 months before the event giving rise to the claim.</p>

    <h2 id="indemnity">12. Indemnity</h2>
    <p>You agree to defend and indemnify {{ $company }} against claims, damages and costs arising from your Customer Data, your messages, your breach of these Terms or of WhatsApp’s policies, or your violation of law or third-party rights.</p>

    <h2 id="law">13. Governing law</h2>
    <p>These Terms are governed by the laws of {{ $jurisdiction }}. The courts of {{ $jurisdiction }} have exclusive jurisdiction over any dispute, unless mandatory law where you are based gives you other rights.</p>

    <h2 id="changes">14. Changes to these Terms</h2>
    <p>We may update these Terms. The “Effective date” above shows the latest version. If a change is material, we will notify you by email or in the dashboard. If you keep using the Service after a change takes effect, you accept the updated Terms.</p>

    <h2 id="contact">15. Contact</h2>
    <p>{{ $company }}<br>
        Email: <a href="mailto:{{ $email }}">{{ $email }}</a><br>
        @if (config('legal.address')) Address: {{ config('legal.address') }}<br> @endif
        Website: {{ $site }}</p>
</x-legal-layout>
