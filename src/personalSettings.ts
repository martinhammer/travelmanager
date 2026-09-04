import { createApp } from 'vue'

// The toasts (showSuccess/showError) are a Vue component whose CSS-module styles
// live in this stylesheet. It only ever reached the bundle transitively, via
// whichever @nextcloud/vue component happened to pull @nextcloud/dialogs in — and
// when that stopped being true the toast container lost its `position: fixed` and
// every toast rendered invisibly at the end of <body>. Import it explicitly.
import '@nextcloud/dialogs/style.css'

import PersonalSettings from './PersonalSettings.vue'

const app = createApp(PersonalSettings)
app.mount('#travelmanager-personal-settings')
