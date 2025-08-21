import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['pattern', 'preview']

    connect() {
        // Set up event listeners
        this.setupEventListeners()
        
        // Generate initial preview if we have the targets
        if (this.hasPatternTarget && this.hasPreviewTarget) {
            this.updatePreview()
        }
    }

    setupEventListeners() {
        // Auto-update preview when pattern changes
        if (this.hasPatternTarget) {
            this.patternTarget.addEventListener('input', () => this.updatePreview())
            this.patternTarget.addEventListener('change', () => this.updatePreview())
        }
    }

    updatePreview() {
        if (!this.hasPatternTarget || !this.hasPreviewTarget) {
            return
        }

        const pattern = this.patternTarget.value
        
        if (!pattern) {
            this.previewTarget.innerHTML = '<i class="fas fa-eye"></i> Preview will appear here'
            return
        }
        
        // Real example data based on FileNaming service variables
        const exampleData = {
            artist: 'Pink Floyd',
            artist_folder: 'Pink Floyd',
            album: 'The Dark Side of the Moon',
            title: 'Time',
            trackNumber: '04',
            year: '1973',
            extension: 'flac',
            quality: '1411kbps',
            quality_short: '1411',
            format: 'FLAC',
            format_short: 'FLAC',
            bitrate: '1411kbps',
            bitrate_short: '1411',
            medium: 'CD 1',
            medium_short: 'CD1',
            quality_badge: 'FLAC - 1411 kbps - 16/44.1kHz',
            quality_full: 'FLAC - 1411 kbps - 16/44.1kHz',
            mediums_count: 2, // Multi-disc album for conditional examples
            // Mock track object for advanced Twig access
            track: {
                title: 'Time',
                trackNumber: '4',
                album: {
                    title: 'The Dark Side of the Moon',
                    releaseDate: { format: (fmt) => fmt === 'Y' ? '1973' : '1973-03-01' },
                    artist: { name: 'Pink Floyd' },
                    mediums: { count: () => 2 }
                },
                medium: {
                    title: null,
                    format: 'CD',
                    position: 1,
                    displayName: 'CD 1'
                },
                files: [
                    {
                        filePath: '/music/Pink Floyd/The Dark Side of the Moon/04 - Time.flac',
                        format: 'FLAC',
                        quality: 'FLAC - 1411 kbps - 16/44.1kHz'
                    }
                ]
            }
        }
        
        let previewText = pattern
        
        // Check if pattern contains Twig syntax
        if (this.containsTwigSyntax(pattern)) {
            previewText = this.renderTwigPreview(pattern, exampleData)
        } else {
            // Legacy replacement for backward compatibility
            Object.keys(exampleData).forEach(key => {
                const regex = new RegExp('{{' + key + '}}', 'g')
                previewText = previewText.replace(regex, exampleData[key])
            })
        }
        
        // Check if the pattern already has an extension (from {{extension}} placeholder)
        const hasExtension = previewText.match(/\.\w+$/)
        
        // Show the final result with proper folder structure
        const finalFilePath = hasExtension ? previewText : previewText + '.flac'
        
        // Clean up only for display (remove invalid characters but keep slashes)
        const displayPath = this.cleanupDisplayPath(previewText)
        const displayFinalPath = hasExtension ? displayPath : displayPath + '.flac'
        
        this.previewTarget.innerHTML = `
            <div>
                <strong>Preview Result:</strong><br>
                <code class="d-block mt-1 p-2 bg-success text-white">${displayFinalPath}</code>
            </div>
        `
    }

    cleanupDisplayPath(text) {
        // Clean up the display path but preserve folder structure
        return text
            .replace(/[<>:"|?*]/g, '_') // Replace invalid filename characters (but keep slashes)
            .replace(/\s+/g, ' ') // Replace multiple spaces with single space
            .replace(/\/+/g, '/') // Replace multiple slashes with single slash
            .replace(/\\+/g, '\\') // Replace multiple backslashes with single backslash
            .trim()
    }

    cleanupPreviewText(text) {
        // Clean up the preview text for actual filename use
        return text
            .replace(/[<>:"\/\\|?*]/g, '_') // Replace invalid filename characters
            .replace(/\s+/g, ' ') // Replace multiple spaces with single space
            .replace(/\/+/g, '/') // Replace multiple slashes with single slash
            .replace(/\\+/g, '\\') // Replace multiple backslashes with single backslash
            .trim()
    }

    togglePattern(event) {
        const patternId = event.currentTarget.dataset.patternId
        
        fetch(`/file-naming-patterns/${patternId}/toggle`, {
            method: 'POST'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload()
            } else {
                this.showErrorMessage(data.error || 'Error toggling pattern')
            }
        })
        .catch(error => {
            console.error('Error:', error)
            this.showErrorMessage('Error toggling pattern')
        })
    }

    deletePattern(event) {
        const patternId = event.currentTarget.dataset.patternId
        
        if (confirm('Are you sure you want to delete this naming pattern?')) {
            fetch(`/file-naming-patterns/${patternId}/delete`, {
                method: 'DELETE'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    event.currentTarget.closest('tr').remove()
                    this.showSuccessMessage('Pattern deleted successfully')
                } else {
                    this.showErrorMessage(data.error || 'Error deleting pattern')
                }
            })
            .catch(error => {
                console.error('Error:', error)
                this.showErrorMessage('Error deleting pattern')
            })
        }
    }

    showSuccessMessage(message) {
        // Create a temporary success alert
        const alertDiv = document.createElement('div')
        alertDiv.className = 'alert alert-success alert-dismissible fade show position-fixed'
        alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;'
        alertDiv.innerHTML = `
            <i class="fas fa-check-circle me-2"></i>
            <strong>Success!</strong> ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `
        document.body.appendChild(alertDiv)
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.remove()
            }
        }, 5000)
    }

    showErrorMessage(message) {
        // Create a temporary error alert
        const alertDiv = document.createElement('div')
        alertDiv.className = 'alert alert-danger alert-dismissible fade show position-fixed'
        alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;'
        alertDiv.innerHTML = `
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Error!</strong> ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `
        document.body.appendChild(alertDiv)
        
        // Auto-remove after 8 seconds
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.remove()
            }
        }, 8000)
    }

    /**
     * Check if pattern contains Twig syntax
     */
    containsTwigSyntax(pattern) {
        return /\{%.*?%\}|\{\{(?!\{).*?(?<!\})\}\}/.test(pattern)
    }

    /**
     * Basic Twig-like rendering for preview (simplified client-side implementation)
     */
    renderTwigPreview(pattern, data) {
        let result = pattern

        try {
            // Handle simple if conditions: {% if variable > value %}content{% endif %}
            result = result.replace(/\{%\s*if\s+(\w+)\s*>\s*(\d+)\s*%\}(.*?)\{%\s*endif\s*%\}/g, (match, variable, value, content) => {
                const varValue = data[variable]
                if (varValue !== undefined && parseInt(varValue) > parseInt(value)) {
                    return content
                }
                return ''
            })

            // Handle simple if conditions: {% if variable %}content{% endif %}
            result = result.replace(/\{%\s*if\s+(\w+)\s*%\}(.*?)\{%\s*endif\s*%\}/g, (match, variable, content) => {
                const varValue = data[variable]
                if (varValue && varValue !== '' && varValue !== '0' && varValue !== 0) {
                    return content
                }
                return ''
            })

            // Handle variable substitution: {{ variable }}
            result = result.replace(/\{\{\s*(\w+)\s*\}\}/g, (match, variable) => {
                return data[variable] || ''
            })

        } catch (error) {
            console.warn('Twig preview rendering error:', error)
            // Fallback to legacy rendering
            Object.keys(data).forEach(key => {
                const regex = new RegExp('{{' + key + '}}', 'g')
                result = result.replace(regex, data[key])
            })
        }

        return result
    }
}
