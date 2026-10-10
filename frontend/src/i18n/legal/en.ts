import type { LegalTexts } from './types.ts'

/**
 * English legal texts: a translation of `es.ts` (the source). Keep both in
 * step; see docs/specs/legal-compliance-eu.md before changing a sentence.
 */
export const legalEn: LegalTexts = {
  privacy: {
    title: 'Privacy policy',
    intro:
      'This policy explains which personal data Chartiko processes, why, on what legal basis and for how long, and how you can exercise your rights.',
    sections: [
      {
        heading: 'Data controller',
        items: [
          'Controller: {ownerName}',
          'Tax ID (NIF): {ownerTaxId}',
          'Address: {ownerAddress}',
          'Contact and data protection: {ownerEmail}',
        ],
      },
      {
        heading: 'User account',
        paragraphs: [
          'If you create an account we process your name, your email and your password, which is stored as a hash and never in plain text. We also keep the Screeners and the Watchlist you choose to save.',
        ],
        items: [
          'Purpose: to create and maintain your account and provide the features for registered users.',
          'Legal basis: performance of the terms of use you accept when you sign up (Art. 6(1)(b) GDPR).',
          'Retention: while you keep the account. If you ask us to delete it we do so; backups may keep it for up to {backupDays} more days.',
        ],
      },
      {
        heading: 'Sessions and sign-in security',
        paragraphs: [
          'To keep you signed in and protect accounts we record the IP address, the browser (user agent) and the time of the last activity of each session, as well as sign-ins, failed attempts (with the email entered, which may not belong to any account), sign-outs and sessions ended by the administration.',
        ],
        items: [
          'Purpose: to keep the session, detect unauthorised access or repeated attempts, and let the administration end open sessions. It is not used for advertising or to track your browsing.',
          'Legal basis: legitimate interest in ensuring network and information security (Art. 6(1)(f) GDPR).',
          'Retention: sessions expire after {sessionMinutes} minutes of inactivity; the sign-in activity log is deleted automatically after {activityDays} days.',
        ],
      },
      {
        heading: 'Server logs',
        paragraphs: [
          'When you visit Chartiko, the web server records the IP address, date and time, the page requested and the browser, and the application records any technical errors.',
        ],
        items: [
          'Purpose: to run the service, diagnose errors and protect it against abuse.',
          'Legal basis: legitimate interest (Art. 6(1)(f) GDPR).',
          'Retention: {logDays} days.',
        ],
      },
      {
        heading: 'Backups',
        paragraphs: [
          'We make a daily copy of the database so the service can be restored after a failure. It is stored with restricted access.',
        ],
        items: [
          'Legal basis: legitimate interest in the continuity and integrity of the service (Art. 6(1)(f) GDPR).',
          'Retention: {backupDays} days.',
        ],
      },
      {
        heading: 'Enquiries and rights requests',
        paragraphs: ['If you write to us, we process your email and what you tell us in order to reply.'],
        items: [
          'Legal basis: compliance with legal obligations when handling your rights (Art. 6(1)(c) GDPR) and legitimate interest in answering other enquiries (Art. 6(1)(f) GDPR).',
          'Retention: as long as needed to handle the request and then for the legal periods during which liability could be claimed.',
        ],
      },
      {
        heading: 'What we do not do',
        paragraphs: [
          'We use no analytics, advertising or social media tools, send no marketing communications, and do no profiling or automated decision-making that affects you. The market data Chartiko shows is fetched by the server and involves no visitor data.',
        ],
      },
      {
        heading: 'Recipients and international transfers',
        paragraphs: [
          'We do not share your data with third parties unless required by law. The hosting provider, OVH, processes the data on behalf of Chartiko as a data processor.',
          'The server is located in Canada (Beauharnois, Quebec). The European Commission recognises an adequate level of protection in Canada (Decision 2002/2/EC), on which this international transfer relies.',
        ],
      },
      {
        heading: 'Your rights',
        paragraphs: [
          'You can request access to, rectification, erasure, objection to, restriction of processing and portability of your data by writing to {ownerEmail}. We will reply within one month.',
          'If you believe we have not handled your request properly, you can lodge a complaint with the Spanish Data Protection Agency (www.aepd.es).',
        ],
      },
      {
        heading: 'Children',
        paragraphs: ['Chartiko is not intended for children under 14, who must not sign up.'],
      },
      {
        heading: 'Security',
        paragraphs: [
          'We apply proportionate technical and organisational measures: encrypted connections (HTTPS), hashed passwords, restricted administrative access, a database not reachable from the Internet and private backups.',
        ],
      },
      {
        heading: 'Cookies',
        paragraphs: ['For cookies and storage in your browser, see the {cookiesLink}.'],
      },
    ],
  },
  notice: {
    title: 'Legal notice and terms of use',
    intro:
      'Information about the owner of Chartiko (Spanish Law 34/2002 on information society services) and the terms that apply to the use of the service.',
    sections: [
      {
        heading: 'Owner',
        items: [
          'Owner: {ownerName}',
          'Tax ID (NIF): {ownerTaxId}',
          'Address: {ownerAddress}',
          'Contact email: {ownerEmail}',
        ],
      },
      {
        heading: 'Purpose',
        paragraphs: [
          'Chartiko (www.chartiko.com) is a web tool for technical analysis of stocks: it filters a universe of stocks with technical criteria, lists ranked Candidates and shows charts with indicators and Signals. Registered users can save Screeners and keep a Watchlist. Using the service requires no payment.',
        ],
      },
      {
        heading: 'Not investment advice',
        paragraphs: [
          'Chartiko information (Screener, Candidates, Signals and charts) is generated automatically from market data and technical indicators and is for information and educational purposes only. It is not investment advice, a personal recommendation or an offer to buy or sell financial instruments.',
          'Data may contain errors or delays. Past performance does not guarantee future results and investing involves the risk of loss. Consult an authorised professional before making investment decisions.',
        ],
      },
      {
        heading: 'Terms of use',
        items: [
          'To sign up you must be at least 14 years old and provide accurate details. You are responsible for keeping your password safe.',
          'You must use the service lawfully, without trying to access unauthorised areas or data, overloading it or extracting its data in bulk or by automated means.',
          'We may suspend or close accounts that breach these terms.',
          'You can stop using the service and ask us to delete your account at any time by writing to {ownerEmail}.',
          'We may change the service and these terms; the update date shows the latest version.',
        ],
      },
      {
        heading: 'Intellectual property',
        paragraphs: [
          'The design, software, brand and texts of Chartiko belong to its owner or are used under licence. Market data comes from third-party sources and may be subject to their own terms; it may not be reused commercially through Chartiko.',
        ],
      },
      {
        heading: 'Liability',
        paragraphs: [
          'We aim to keep the service available and the data correct, but interruptions, errors or delays may occur. We are not liable for decisions you make based on the information shown or for the content of external sites.',
        ],
      },
      {
        heading: 'Governing law',
        paragraphs: [
          'These terms are governed by Spanish law. If you act as a consumer, you keep the rights consumer law grants you, including bringing proceedings in the courts of your place of residence.',
          'How we process your data is explained in the {privacyLink}.',
        ],
      },
    ],
  },
  cookies: {
    title: 'Cookie policy',
    intro:
      'Cookies and similar technologies, such as the browser’s local storage, store information on your device. This table lists every one Chartiko uses.',
    rows: [
      {
        name: 'alphapulse-session',
        type: 'First-party cookie (HttpOnly, secure)',
        purpose: 'Keeps your session and links it to your requests.',
        duration: '{sessionMinutes} minutes',
        category: 'Technical, necessary',
      },
      {
        name: 'XSRF-TOKEN',
        type: 'First-party cookie (secure)',
        purpose: 'Protects forms against forged requests (CSRF).',
        duration: '{sessionMinutes} minutes',
        category: 'Technical, necessary',
      },
      {
        name: 'chartiko.locale',
        type: 'First-party local storage',
        purpose: 'Remembers the language you choose.',
        duration: 'Until you clear it',
        category: 'Preference you choose',
      },
    ],
    sections: [
      {
        heading: 'Why there is no cookie banner',
        paragraphs: [
          'All of them are strictly necessary to provide the service you request or store a preference you choose, so they need no consent (Art. 22.2 of Spanish Law 34/2002 and the AEPD guide). Chartiko uses no analytics, advertising or third-party cookies and loads no resources from other domains.',
        ],
      },
      {
        heading: 'How to remove them',
        paragraphs: [
          'You can delete or block cookies and local storage in your browser settings. If you block the technical cookies you will not be able to sign in; if you clear the language preference, Chartiko will use your browser’s language.',
        ],
      },
      {
        heading: 'Changes',
        paragraphs: [
          'If we add new cookies or tools we will update this policy and, if they need consent, ask for it before using them.',
        ],
      },
    ],
  },
}
