# Travel Manager

A personal project for AI-assisted travel itinerary management as a Nextcloud app. 

The app integrates with the users' dedicated mailbox from which it reads travel booking emails. These are then passed to Nextcloud Assistant to classify (currently flight, car rental, accommodation) and extract the relevant information (e.g. dates, passengers, providers, etc.) into a structured schema. The extracted bookings are stored in Nextcloud and can then be grouped into trips in the application UI. A mobile client is envisioned for the future.

The AI text-processing provider is configured outside of the application itself in the standard Assistant settings.

Dependencies and prerequisites:
* Dedicated IMAP mailbox - it is assumed all emails in this mailbox are travel-related and as such are passed to the AI for classification and extraction
* [Nextcloud Assistant](https://apps.nextcloud.com/apps/assistant) 
* [OpenAI and LocalAI integration](https://apps.nextcloud.com/apps/integration_openai) - properly configured in the Assistant settings

Know limitations and issues:
* The app is under heavy iterative development. Whilst migration scripts are being used to move "seamlessly" between versions, there is a lot of churn and the app is not yet suitable for production use.
* Currently there is 1:N relationship between email and bookings. In other words, a later email will not update an existing booking (it might create a duplicate or fail to do anything).
* The full booking and trip lifecycle is not yet 100% nailed down, and neither is the full relationship between the objects (message/booking/trip).
* Limited testing on a small sample of bookings till date.

### Motivation

This is currently a personal project but it is evolving and may at some point become useful to others. In addition to the actual usage, I am using this to learn about Nextcloud app development and AI-assisted development. Significant portion of the code has been written by Claude Code.

### Find this useful? Have a suggestion? Found a bug?

Feel free to get in touch and/or submit an issue.

### Screenshots

Coming soon
