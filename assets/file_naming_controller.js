import { Controller } from "@hotwired/stimulus"

/**
 * File naming pattern Stimulus controller
 * Replaces all inline JavaScript functionality
 */
export default class extends Controller {
    static targets = [
        "previewButton",
        "patternInput",
        "previewOutput"
    ]

    connect() {
        console.log("File naming controller connected")
    }

    // Update preview
    async updatePreview() {
        const pattern = this.patternInputTarget.value
        if (!pattern.trim()) {
            this.previewOutputTarget.innerHTML = '<em class="text-muted">Enter a pattern to see preview</em>'
            return
        }

        try {
            const response = await fetch('/file-naming-patterns/preview', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ pattern })
            })

            const data = await response.json()

            if (data.success) {
                this.previewOutputTarget.innerHTML = `
                    <div class="alert alert-info">
                        <strong>Preview:</strong><br>
                        <code>${this.escapeHtml(data.preview)}</code>
                    </div>
                `
            } else {
                this.previewOutputTarget.innerHTML = `
                    <div class="alert alert-danger">
                        <strong>Error:</strong> ${this.escapeHtml(data.error || 'Invalid pattern')}
                    </div>
                `
            }
        } catch (error) {
            console.error('Error updating preview:', error)
            this.previewOutputTarget.innerHTML = `
                <div class="alert alert-danger">
                    <strong>Error:</strong> Failed to generate preview
                </div>
            `
        }
    }

    // Utility functions
    escapeHtml(text) {
        if (!text) return ''
        const div = document.createElement('div')
        div.textContent = text
        return div.innerHTML
    }
}
