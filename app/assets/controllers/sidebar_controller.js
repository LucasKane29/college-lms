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
        const collapsed = !this.element.classList.contains('workspace-collapsed');
        this.render(collapsed);
        try {
            window.localStorage.setItem('college-lms-sidebar-collapsed', String(collapsed));
        } catch {
            // Storage is optional; toggling remains available.
        }
    }

    render(collapsed) {
        this.element.classList.toggle('workspace-collapsed', collapsed);
        const label = collapsed ? 'Розгорнути меню' : 'Згорнути меню';
        this.toggleTarget.setAttribute('aria-expanded', String(!collapsed));
        this.toggleTarget.setAttribute('aria-label', label);
        this.toggleTarget.setAttribute('title', label);
    }
}
