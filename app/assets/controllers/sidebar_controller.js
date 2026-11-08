import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['toggle'];

    connect() {
        let collapsed = window.matchMedia('(max-width: 700px)').matches;
        try {
            const saved = window.localStorage.getItem('college-lms-sidebar-collapsed');
            if (saved !== null) collapsed = saved === 'true';
        } catch {
            // The menu also works when browser storage is unavailable.
        }
        this.render(collapsed);
    }

    toggle() {
        const collapsed = this.element.dataset.collapsed !== 'true';
        this.render(collapsed);
        try {
            window.localStorage.setItem('college-lms-sidebar-collapsed', String(collapsed));
        } catch {
            // Storage is optional; toggling remains available.
        }
    }

    render(collapsed) {
        this.element.dataset.collapsed = String(collapsed);
        const label = collapsed ? 'Розгорнути меню' : 'Згорнути меню';
        this.toggleTarget.setAttribute('aria-expanded', String(!collapsed));
        this.toggleTarget.setAttribute('aria-label', label);
        this.toggleTarget.setAttribute('title', label);
    }
}
