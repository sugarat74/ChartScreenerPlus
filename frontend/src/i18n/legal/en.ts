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
          'Contact and data protection: {ownerEmail}',
        ],
      },
      {
        heading: 'User account',
        paragraphs: [
          'If you create an account we process your name, your email and your password, which is stored as a hash and never in plain text. We also keep the Screeners and the Watchlist you choose to save.',
        ],
        accountEmail: [
          'When you register we send you an email to confirm that the address is yours, with a link that expires after 24 hours. Until you confirm it, the account only lets you resend that email, manage the account or delete it. We store the confirmation date.',
        ],
        items: [
          'Purpose: to create and maintain your account and provide the features for registered users.',
          'Legal basis: performance of the terms of use you accept when you sign up (Art. 6(1)(b) GDPR).',
          'Retention: while you keep the account. If you delete it from the Account page or ask us to delete it, we delete it immediately together with your Watchlist, saved Screeners, alerts, notifications and sessions; backups may keep it for up to {backupDays} more days and the sign-in activity log keeps the email until it is deleted after {activityDays} days.',
        ],
      },
      {
        heading: 'Alerts and notifications',
        paragraphs: [
          'If you create alerts we store their settings (the Saved Screener or the signal or pattern types you choose for your Watchlist) and, after each daily data update, the notifications listing the instruments that triggered them.',
        ],
        emailDigest: [
          'If you turn on the daily email summary in your Portal (it is off unless you choose it), we store when you turned it on and the email language, and on days when your alerts find something we send one email with their notices to your email address, after the daily data update. The email has no tracking pixels or tracking links. You can unsubscribe with the link in the email itself, without signing in, or from your Portal. We keep a record of each send (data date, number of notices and send time) that is deleted after {emailDigestDays} days.',
        ],
        items: [
          'Purpose: to let you know inside Chartiko about the changes you asked to follow.',
          'Legal basis: performance of the terms of use of the service (Art. 6(1)(b) GDPR).',
          'Retention: alerts while you keep them; notifications are deleted automatically after {notificationDays} days.',
        ],
      },
      {
        heading: 'Sessions and sign-in security',
        paragraphs: [
          'To keep you signed in and protect accounts we record the IP address, the browser (user agent) and the time of the last activity of each session, as well as sign-ins, failed attempts (with the email entered, which may not belong to any account), sign-outs, sessions ended by the administration and account deletions (email only). Visitors without an account also have a technical session with the IP address, browser and last activity.',
        ],
        items: [
          'Purpose: to keep the session, detect unauthorised access or repeated attempts, and let the administration end open sessions. It is not used for advertising or to track your browsing.',
          'Legal basis: legitimate interest in ensuring network and information security (Art. 6(1)(f) GDPR).',
          'Retention: sessions expire after {sessionMinutes} minutes of inactivity and expired ones are deleted periodically; the sign-in activity log is deleted automatically after {activityDays} days.',
        ],
      },
      {
        heading: 'Server logs',
        paragraphs: [
          'When you visit Chartiko, the web server records the IP address, date and time, the page requested, the referring page (referer) and the browser, and the application records any technical errors.',
        ],
        items: [
          'Purpose: to run the service, diagnose errors and protect it against abuse.',
          'Legal basis: legitimate interest (Art. 6(1)(f) GDPR).',
          'Retention: {logDays} days.',
        ],
      },
      {
        heading: 'AI features',
        paragraphs: [
          'If you are a registered user and ask for an AI company card, the server sends DevExpert Inference (inference.devexpert.io), the external AI provider, the instrument identity (ticker, company name, sector and exchange), the public financial figures Chartiko computes from the company\'s SEC (EDGAR) filings and public Chartiko market data (latest close and change, indicators, Signals and patterns). The generated card is shared with every user and contains no personal data.',
          'If you are a registered user and ask for an experimental forecast, the server sends TypeSafe AI (typesafe.ai), provider of the Jev model, the instrument\'s daily price history, its indicators and its Signals. The forecast is stored per instrument and date, not per user.',
          'In neither case is any of your account data sent (no name, email, IP address, Watchlist or Saved Screeners): the providers only receive market data.',
          'If you are a registered user and describe in your own words what you are looking for in the Screener, the server sends that text and the page language to DevExpert Inference to translate it into the Screener filters, without any of your account data. Chartiko does not store or log your text; it only keeps the translated result (filters, explanation and the parts that could not be translated) for 24 hours to reuse it for identical requests. Do not include personal data in the text.',
          'To control cost and prevent abuse we record each generation and each AI search you request: your account identifier, the instrument (for cards), the date, the language, the length of the search text, the outcome and the number of tokens used, never the text sent or received.',
        ],
        items: [
          'Purpose: to generate the card you ask for or translate your search into filters, limit daily use and prevent abuse.',
          'Legal basis: performance of the terms of use to generate the card or translate the search (Art. 6(1)(b) GDPR) and legitimate interest in controlling cost and preventing abuse for the logs (Art. 6(1)(f) GDPR).',
          'Retention: the generation log is deleted automatically after {aiCardLogDays} days and the AI search log after {aiScreenerLogDays} days.',
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
          'We use no analytics, advertising or social media tools, send no marketing communications or alert emails, and do no profiling or automated decision-making that affects you. The market data Chartiko shows is fetched by the server and involves no visitor data.',
        ],
      },
      {
        heading: 'Recipients and international transfers',
        paragraphs: [
          'We do not share your data with third parties unless required by law. The hosting provider, OVH, processes the data on behalf of Chartiko as a data processor.',
          'If you use the Screener AI search, the text you type is sent to DevExpert Inference, the AI provider, only to return the filters (see "AI features"); that is why we ask you not to include personal data.',
          'The server is located in Canada (Beauharnois, Quebec). The European Commission recognises an adequate level of protection in Canada (Decision 2002/2/EC), on which this international transfer relies.',
        ],
        accountEmail: [
          'The emails of your account (such as the confirmation of your address) are sent through {emailProvider}, the email delivery provider, which processes them on behalf of Chartiko as a data processor. Processing location: {emailProviderLocation}.',
        ],
        emailDigest: [
          'If you turn on the daily email summary, your email address and the email content are sent to {emailProvider}, the email delivery provider, which processes them on behalf of Chartiko as a data processor. Processing location: {emailProviderLocation}.',
        ],
      },
      {
        heading: 'Your rights',
        paragraphs: [
          'If you have an account, the Account page in your Portal lets you download a copy of your data as JSON (access and portability), correct your name (rectification) and delete the account (erasure) yourself, immediately.', 'You can also request access to, rectification, erasure, objection to, restriction of processing and portability of your data by writing to {ownerEmail}; for example, to change your email. We will reply within one month.',
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
          'Financial figures are computed by Chartiko from public SEC EDGAR filings and our latest close and may differ from other sources or lag the latest filing; the AI-written outlook may contain errors and is not verified.',
          'Data may contain errors or delays. Past performance does not guarantee future results and investing involves the risk of loss. Consult an authorised professional before making investment decisions.',
        ],
      },
      {
        heading: 'Terms of use',
        items: [
          'To sign up you must be at least 14 years old and provide accurate details. You are responsible for keeping your password safe.',
          'You must use the service lawfully, without trying to access unauthorised areas or data, overloading it or extracting its data in bulk or by automated means.',
          'We may suspend or close accounts that breach these terms.',
          'You can stop using the service and delete your account at any time from the Account page in your Portal or by writing to {ownerEmail}.',
          'We may change the service and these terms; the update date shows the latest version.',
        ],
        marketContext: [
          'The macroeconomic data on the Market page come from the FRED® API of the Federal Reserve Bank of St. Louis. By using Chartiko you agree to be bound by the {fredTermsLink}.',
        ],
      },
      {
        heading: 'Intellectual property',
        paragraphs: [
          'The design, software, brand and texts of Chartiko belong to its owner or are used under licence. Market data comes from third-party sources and may be subject to their own terms; it may not be reused commercially through Chartiko. Company financial figures are computed from public filings in the EDGAR system of the U.S. Securities and Exchange Commission (SEC).',
        ],
        marketContext: [
          'The macroeconomic data come from FRED® and their original sources (the Federal Reserve Board, the U.S. Department of Labor and Census Bureau, the European Central Bank and the OECD), cited next to each figure. Chartiko uses the FRED® API but is not endorsed or certified by the Federal Reserve Bank of St. Louis.',
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
      {
        name: 'chartiko.theme',
        type: 'First-party local storage',
        purpose: 'Remembers the color theme (light or dark) you choose.',
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
          'You can delete or block cookies and local storage in your browser settings. If you block the technical cookies you will not be able to sign in; if you clear the language or theme preference, Chartiko will use your browser’s language or color theme.',
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
