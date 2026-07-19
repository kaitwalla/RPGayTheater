import { createApp } from 'vue';
import '../css/app.css';

createApp({
    template: '<main class="shell"><section class="panel"><div class="eyebrow">Theatrical RPG</div><h1>Presentation</h1><p class="muted">This entry point is activated only by a paired display session.</p></section></main>',
}).mount('#app');
