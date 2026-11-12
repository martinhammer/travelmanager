# Travel Manager

A personal project for AI-assisted travel itinerary management as a Nextcloud app.

The app integrates with the users' dedicated mailbox from which it reads travel booking emails (currently flight/train/bus, car rental, accommodation). These are passed to Nextcloud Assistant to classify and extract the relevant information such as dates, booking reference, passengers, providers, etc. into a structured schema. The extracted bookings are stored in Nextcloud and can then be grouped into trips in the application UI. Care has been taken that the app is usable from both desktop and mobile browser, with an intuitive UI including a calendar view.

The AI text-processing provider is configured outside of the application itself in the standard Assistant settings. Please be mindful that your personal details will be passed on to and processed by whatever AI provider is configured by the server administrator.

Dependencies and prerequisites:
* Dedicated IMAP mailbox - it is assumed all emails in this mailbox are travel-related and as such are passed to the AI for classification and extraction
* [Nextcloud Assistant](https://apps.nextcloud.com/apps/assistant)
* [OpenAI and LocalAI integration](https://apps.nextcloud.com/apps/integration_openai) - properly configured in the Assistant settings

Know limitations and issues:
* The app is under heavy iterative development. Whilst migration scripts are being used to move "seamlessly" between versions, caution is advised in production scenarios.
* Currently there is 1:N relationship between email message and bookings. In other words, a later email will not update an existing booking (in most cases a "duplicate" should be detected and highlighted).
* Limited testing on a relatively small sample (~100) of bookings till date.
* Please be aware that the email content with potentially sensitive personal data is being submitted to the AI model configured by the Nextcloud instance administrator in Assistant settings.

### Motivation

This is currently a personal project but it is evolving and may at some point become useful to others. In addition to the actual usage, I am using this to learn about Nextcloud app development and AI-assisted development. Significant portion of the code has been written by Claude Code.

### Find this useful? Have a suggestion? Want to get involved?

Feel free to get in touch and/or submit an issue.

### Screenshots

Coming soon
