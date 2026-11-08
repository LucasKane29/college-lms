import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['discipline', 'topic'];

    connect() {
        this.filter();
    }

    filter() {
        const discipline = this.disciplineTarget.value;
        for (const option of this.topicTarget.options) {
            const available = !option.value || option.dataset.disciplineId === discipline;
            option.hidden = !available;
            option.disabled = !available;
            if (!available && option.selected) this.topicTarget.value = '';
        }
        for (const group of this.topicTarget.querySelectorAll('optgroup')) {
            group.hidden = !Array.from(group.children).some((option) => !option.hidden);
        }
    }
}
