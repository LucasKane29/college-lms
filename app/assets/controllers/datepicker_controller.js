import { Controller } from '@hotwired/stimulus';
import '../lib/flatpickr/flatpickr.min.js';
import '../lib/flatpickr/uk.js';

export default class extends Controller {
    connect() {
        this.picker = window.flatpickr(this.element, {
            locale: {
                ...window.flatpickr.l10ns.uk,
                yearAriaLabel: 'Рік',
                monthAriaLabel: 'Місяць',
                scrollTitle: 'Прокрутіть для зміни',
                toggleTitle: 'Натисніть для перемикання',
                weekAbbreviation: 'Тиж.',
            },
            dateFormat: 'Y-m-d',
            altInput: true,
            altFormat: 'd.m.Y',
            ariaDateFormat: 'j F Y',
            allowInput: true,
            disableMobile: true,
            static: true,
            minDate: this.element.getAttribute('min') || undefined,
            maxDate: this.element.getAttribute('max') || undefined,
        });
        const visible = this.picker.altInput;
        this.picker.prevMonthNav.title = 'Попередній місяць';
        this.picker.nextMonthNav.title = 'Наступний місяць';
        this.originalId = this.element.id;
        // Keep the label and error descriptions attached to the visible input.
        if (this.originalId) {
            this.element.removeAttribute('id');
            visible.id = this.originalId;
        }
        for (const name of ['aria-label', 'aria-describedby', 'aria-invalid', 'autocomplete']) {
            const value = this.element.getAttribute(name);
            if (value !== null) visible.setAttribute(name, value);
        }
        visible.placeholder = 'дд.мм.рррр';
        visible.setAttribute('lang', 'uk');
        this.onEscape = (event) => {
            if (event.key === 'Escape' && this.picker.isOpen) {
                event.preventDefault();
                event.stopPropagation();
                this.picker.close();
            }
        };
        visible.addEventListener('keydown', this.onEscape);
        this.picker.calendarContainer.addEventListener('keydown', this.onEscape);
    }

    disconnect() {
        this.picker.altInput.removeEventListener('keydown', this.onEscape);
        this.picker.calendarContainer.removeEventListener('keydown', this.onEscape);
        this.picker.destroy();
        if (this.originalId) this.element.id = this.originalId;
    }
}
