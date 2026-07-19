import { createApp } from 'vue';
import '../css/app.css';

createApp({
    template: '<main class="shell"><section class="panel"><div class="eyebrow">Theatrical RPG</div><h1>Player</h1><p class="muted">This entry point is activated only by an active player session.</p></section></main>',
}).mount('#app');
