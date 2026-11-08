import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['list'];
    static values = { prototype: String, placeholder: String, index: Number };

    add(event) {
        event.preventDefault();
        event.stopPropagation();
        const template = document.createElement('template');
        template.innerHTML = this.prototypeValue.replaceAll(this.placeholderValue, String(this.indexValue++));
        this.listTarget.append(template.content);
        this.listTarget.lastElementChild?.querySelector('textarea, input')?.focus();
    }

    remove(event) {
        event.preventDefault();
        // Nested answer collections must not remove their parent question too.
        event.stopPropagation();
        event.currentTarget.closest('[data-collection-item]')?.remove();
    }
}
