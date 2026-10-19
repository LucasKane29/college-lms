import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.format();
        this.validate();
    }

    focus() {
        if (this.element.value === '') {
            this.element.value = '+380 (';
            this.element.setSelectionRange(this.element.value.length, this.element.value.length);
        }
        this.validate();
    }

    blur() {
        if (this.element.value.replace(/\D/g, '') === '380') this.element.value = '';
        this.validate();
    }

    input() {
        this.format();
        this.validate();
    }

    beforeInput(event) {
        const backward = event.inputType === 'deleteContentBackward';
        const forward = event.inputType === 'deleteContentForward';
        if ((!backward && !forward) || this.element.selectionStart !== this.element.selectionEnd) return;
        const value = this.element.value;
        let index = this.element.selectionStart - (backward ? 1 : 0);
        while (index >= 0 && index < value.length && !/[0-9]/.test(value[index])) index += backward ? -1 : 1;
        if (index < 0 || index >= value.length) return;
        event.preventDefault();
        if (value.startsWith('+380') && index < 4) {
            this.element.value = '';
            this.validate();
            return;
        }
        this.element.value = value.slice(0, index) + value.slice(index + 1);
        this.element.setSelectionRange(index, index);
        this.input();
    }

    format() {
        const raw = this.element.value;
        const digits = raw.replace(/\D/g, '');
        if (digits === '') {
            this.element.value = '';
            return;
        }
        let normalized = digits;
        if (!raw.trim().startsWith('+')) {
            if (digits.startsWith('0')) normalized = '38' + digits;
            else if (!digits.startsWith('380') && digits.length <= 9) normalized = '380' + digits;
        }
        const caret = this.element.selectionStart ?? raw.length;
        const digitsBefore = raw.slice(0, caret).replace(/\D/g, '').length + normalized.length - digits.length;
        let formatted = '+' + normalized;
        if (normalized.startsWith('380') && normalized.length <= 12) {
            const number = normalized.slice(3);
            formatted = '+380 (' + number.slice(0, 2);
            if (number.length >= 2) formatted += ') ';
            if (number.length > 2) formatted += number.slice(2, 5);
            if (number.length > 5) formatted += '-' + number.slice(5, 7);
            if (number.length > 7) formatted += '-' + number.slice(7, 9);
        }
        this.element.value = formatted;
        // Keep the caret beside the same digit when editing the middle of the mask.
        let position = 0;
        let seen = 0;
        while (position < formatted.length && seen < digitsBefore) {
            if (/[0-9]/.test(formatted[position])) seen++;
            position++;
        }
        while (position < formatted.length && !/[0-9]/.test(formatted[position])) position++;
        this.element.setSelectionRange(position, position);
    }

    validate() {
        const value = this.element.value.replace(/[\s()\-]/g, '');
        const valid = value === '' || /^\+(?:380[0-9]{9}|(?!380)[1-9][0-9]{7,14})$/.test(value);
        this.element.setCustomValidity(valid ? '' : 'Вкажіть повний номер телефона, наприклад +380 (67) 123-45-67.');
    }
}
