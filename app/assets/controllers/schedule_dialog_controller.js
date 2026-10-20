import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.opener = document.activeElement;
        if (this.element.open) this.element.close();
        this.element.showModal();
    }

    disconnect() {
        if (this.element.open) this.element.close();
        if (this.opener?.isConnected) this.opener.focus({ preventScroll: true });
    }

    close(event) {
        const frame = this.element.closest('turbo-frame');
        if (!document.querySelector('.schedule-table')) return;
        event?.preventDefault();
        this.element.close();
        frame.replaceChildren();
    }

    backdrop(event) {
        if (event.target !== this.element) return;
        const bounds = this.element.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) this.close(event);
    }
}
