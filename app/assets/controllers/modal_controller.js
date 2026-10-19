import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { userId: Number };

    connect() {
        this.opener = document.activeElement;
        // The open attribute leaves the form usable when JavaScript is unavailable.
        if (this.element.open) this.element.close();
        this.element.showModal();
    }

    disconnect() {
        if (this.element.open) this.element.close();
        const target = this.opener?.isConnected ? this.opener :
            document.querySelector(`[data-edit-user-id="${this.userIdValue}"]`) ?? document.getElementById('user-catalog');
        target?.focus({ preventScroll: true });
    }

    cancel(event) {
        event?.preventDefault();
        this.element.close();
        this.element.closest('turbo-frame').replaceChildren();
    }

    backdrop(event) {
        if (event.target !== this.element) return;
        const bounds = this.element.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) {
            this.cancel(event);
        }
    }
}
