import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = [
        'previewBtn', 'renameBtn', 'patternSelect', 'totalTracksCount', 'selectedCount',
        'tracksTableBody', 'loadingIndicator', 'endOfDataIndicator', 'infiniteScrollTrigger',
        'previewModal', 'previewContent', 'confirmRenameModal'
    ]

    connect() {
        this.currentPreviews = []
        this.currentPage = 1
        this.hasMoreData = true
        this.isLoading = false
        this.currentFilters = {
            search: '',
            artist: '',
            album: '',
            title: ''
        }

        this.loadTracks()
        this.setupEventListeners()
        this.updateSelection()

        // Auto-select first pattern if available
        if (this.patternSelectTarget && this.patternSelectTarget.options.length > 1) {
            this.patternSelectTarget.selectedIndex = 1
            console.log('🎯 Auto-selected first pattern in file_renaming_controller')
            this.updateSelection()
        } else {
            console.log('⚠️ No patterns available for auto-selection')
            console.log('Pattern select target:', this.patternSelectTarget)
            console.log('Pattern select options:', this.patternSelectTarget?.options?.length)
        }
    }

    setupEventListeners() {
        // Setup infinite scroll observer
        this.setupInfiniteScrollObserver()
    }

    setupInfiniteScrollObserver() {
        if (this.hasInfiniteScrollTriggerTarget) {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting && this.hasMoreData && !this.isLoading) {
                        this.currentPage++
                        this.loadTracks()
                    }
                })
            })

            observer.observe(this.infiniteScrollTriggerTarget)
        }
    }

    updateSelection() {
        console.log('🔄 updateSelection called in file_renaming_controller')
        
        const checkedBoxes = document.querySelectorAll('.track-checkbox:checked')
        const totalBoxes = document.querySelectorAll('.track-checkbox')
        
        console.log('Checked checkboxes:', checkedBoxes)
        console.log('Total checkboxes:', totalBoxes)
        console.log('Checkbox classes:', Array.from(totalBoxes).map(cb => cb.className))
        
        if (this.hasSelectedCountTarget) {
            this.selectedCountTarget.textContent = `Selected: ${checkedBoxes.length}`
        }
        
        // Enable/disable buttons
        const hasSelection = checkedBoxes.length > 0
        const hasPattern = this.patternSelectTarget && this.patternSelectTarget.value !== ''
        
        console.log('Button state check:', {
            hasSelection,
            hasPattern,
            patternValue: this.patternSelectTarget?.value,
            selectedCount: checkedBoxes.length,
            totalCount: totalBoxes.length
        })
        
        if (this.hasPreviewBtnTarget) {
            this.previewBtnTarget.disabled = !hasSelection || !hasPattern
            console.log('Preview button disabled:', this.previewBtnTarget.disabled)
        }
        if (this.hasRenameBtnTarget) {
            this.renameBtnTarget.disabled = !hasSelection || !hasPattern
            console.log('Rename button disabled:', this.renameBtnTarget.disabled)
        }
    }

    toggleAllTracks(event) {
        const trackCheckboxes = document.querySelectorAll('.track-checkbox')
        trackCheckboxes.forEach(checkbox => {
            checkbox.checked = event.target.checked
        })
        this.updateSelection()
    }

    applyFilters() {
        const searchInput = document.getElementById('searchInput')
        const artistFilter = document.getElementById('artistFilter')
        const albumFilter = document.getElementById('albumFilter')
        const titleFilter = document.getElementById('titleFilter')

        this.currentFilters = {
            search: searchInput ? searchInput.value.trim() : '',
            artist: artistFilter ? artistFilter.value.trim() : '',
            album: albumFilter ? albumFilter.value.trim() : '',
            title: titleFilter ? titleFilter.value.trim() : ''
        }
        
        // Reset pagination
        this.currentPage = 1
        this.hasMoreData = true
        
        // Clear current tracks and reload
        this.clearTracksTable()
        this.loadTracks()
    }

    clearFilters() {
        const searchInput = document.getElementById('searchInput')
        const artistFilter = document.getElementById('artistFilter')
        const albumFilter = document.getElementById('albumFilter')
        const titleFilter = document.getElementById('titleFilter')

        if (searchInput) searchInput.value = ''
        if (artistFilter) artistFilter.value = ''
        if (albumFilter) albumFilter.value = ''
        if (titleFilter) titleFilter.value = ''
        
        this.applyFilters()
    }

    clearTracksTable() {
        if (this.hasTracksTableBodyTarget) {
            this.tracksTableBodyTarget.innerHTML = ''
        }
        if (this.hasLoadingIndicatorTarget) {
            this.loadingIndicatorTarget.style.display = 'block'
        }
        if (this.hasInfiniteScrollTriggerTarget) {
            this.infiniteScrollTriggerTarget.style.display = 'none'
        }
        if (this.hasEndOfDataIndicatorTarget) {
            this.endOfDataIndicatorTarget.style.display = 'none'
        }
    }

    loadTracks() {
        if (this.isLoading || !this.hasMoreData) return
        
        this.isLoading = true
        this.showLoadingState()

        const params = new URLSearchParams({
            page: this.currentPage,
            limit: 50,
            ...this.currentFilters
        })

        fetch(`/file-renaming/api/tracks?${params}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.displayTracks(data.tracks)
                    this.updatePaginationState(data.pagination)
                    this.updateTotalCount(data.pagination.total)
                } else {
                    console.error('Error loading tracks:', data.error)
                    this.showErrorState()
                }
            })
            .catch(error => {
                console.error('Error:', error)
                this.showErrorState()
            })
            .finally(() => {
                this.isLoading = false
                this.hideLoadingState()
            })
    }

    displayTracks(tracks) {
        if (this.currentPage === 1) {
            // First page - show table and clear body
            if (this.hasTracksTableBodyTarget) {
                this.tracksTableBodyTarget.innerHTML = ''
            }
            if (this.hasLoadingIndicatorTarget) {
                this.loadingIndicatorTarget.style.display = 'none'
            }
        }

        tracks.forEach(track => {
            const row = this.createTrackRow(track)
            if (this.hasTracksTableBodyTarget) {
                this.tracksTableBodyTarget.appendChild(row)
            }
        })

        // Update selection state
        console.log('🔄 Tracks displayed, updating selection...')
        this.updateSelection()
    }

    createTrackRow(track) {
        const row = document.createElement('tr')
        row.innerHTML = `
            <td>
                <input type="checkbox" class="track-checkbox" value="${track.id}" checked>
            </td>
            <td>
                <span class="badge bg-primary">${track.artist}</span>
            </td>
            <td>
                <span class="badge bg-info">${track.album}</span>
            </td>
            <td>
                <div class="d-flex align-items-center">
                    <strong>
                        <a href="/track/${track.trackId}" class="text-decoration-none">
                            ${track.title}
                        </a>
                    </strong>
                    ${track.hasLyrics ? '<i class="fas fa-file-alt text-success ms-2" title="Lyrics available"></i>' : ''}
                </div>
            </td>
            <td>
                <span class="badge bg-secondary">${track.trackNumber}</span>
            </td>
            <td>
                ${track.filePath ? 
                    `<small class="text-muted font-monospace">${track.filePath}</small>` :
                    `<small class="text-muted text-danger">
                        <i class="fas fa-exclamation-triangle"></i> No file path
                    </small>`
                }
            </td>
            <td>
                <span id="preview-${track.id}" class="text-muted">
                    <i class="fas fa-eye"></i> Preview
                </span>
            </td>
            <td>
                <span class="badge bg-success">Active</span>
            </td>
        `

        // Add event listener manually to ensure it works
        const checkbox = row.querySelector('.track-checkbox')
        if (checkbox) {
            checkbox.addEventListener('change', () => {
                console.log('🔍 Individual checkbox changed:', checkbox.checked, 'for track:', track.id)
                this.updateSelection()
            })
        }

        return row
    }

    updatePaginationState(pagination) {
        this.hasMoreData = pagination.hasNext
        this.currentPage = pagination.page
        
        if (this.hasMoreData && this.hasInfiniteScrollTriggerTarget) {
            this.infiniteScrollTriggerTarget.style.display = 'block'
        } else if (this.hasInfiniteScrollTriggerTarget) {
            this.infiniteScrollTriggerTarget.style.display = 'none'
            if (pagination.total > 0 && this.hasEndOfDataIndicatorTarget) {
                this.endOfDataIndicatorTarget.style.display = 'block'
            }
        }
    }

    updateTotalCount(total) {
        if (this.hasTotalTracksCountTarget) {
            this.totalTracksCountTarget.textContent = total
        }
    }

    showLoadingState() {
        if (this.currentPage === 1 && this.hasLoadingIndicatorTarget) {
            this.loadingIndicatorTarget.style.display = 'block'
        }
    }

    hideLoadingState() {
        if (this.hasLoadingIndicatorTarget) {
            this.loadingIndicatorTarget.style.display = 'none'
        }
    }

    showErrorState() {
        if (this.currentPage === 1 && this.hasTracksTableBodyTarget) {
            this.tracksTableBodyTarget.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center py-5">
                        <i class="fas fa-exclamation-triangle text-danger fa-3x mb-3"></i>
                        <h4>Error loading tracks</h4>
                        <p class="text-muted">Please try again later</p>
                        <button class="btn btn-primary" onclick="location.reload()">Reload</button>
                    </td>
                </tr>
            `
        }
        
        // Show error in console for debugging
        console.error('Error loading tracks. Current filters:', this.currentFilters)
    }

    generatePreview() {
        const patternId = this.patternSelectTarget.value
        const trackIds = Array.from(document.querySelectorAll('.track-checkbox:checked')).map(cb => cb.value)

        if (!patternId || trackIds.length === 0) {
            alert('Cannot generate preview: pattern or tracks missing')
            return
        }

        const formData = new FormData()
        formData.append('pattern_id', patternId)
        trackIds.forEach(id => formData.append('track_ids[]', id))

        fetch('/file-renaming/preview', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                this.currentPreviews = data.previews
                this.displayPreviews(data.previews)
                this.showPreviewModal()
            } else {
                console.error('Error generating preview:', data.error)
                alert('Error generating preview: ' + data.error)
            }
        })
        .catch(error => {
            console.error('Error:', error)
            alert('Error generating preview')
        })
    }

    displayPreviews(previews) {
        previews.forEach(preview => {
            const previewElement = document.getElementById(`preview-${preview.id}`)
            if (previewElement) {
                previewElement.innerHTML = `
                    <div class="small">
                        <div class="text-success"><strong>New:</strong> <code>${preview.new_full_path}</code></div>
                        <div class="text-muted"><small>Current: ${preview.current_name}</small></div>
                    </div>
                `
            }
        })
    }

    showPreviewModal() {
        if (this.hasPreviewModalTarget) {
            // Update preview content with summary
            if (this.hasPreviewContentTarget) {
                const selectedCount = document.querySelectorAll('.track-checkbox:checked').length
                const patternName = this.patternSelectTarget.options[this.patternSelectTarget.selectedIndex]?.text || 'Unknown Pattern'
                
                this.previewContentTarget.innerHTML = `
                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle me-2"></i>Preview Summary</h6>
                        <p class="mb-2"><strong>Pattern:</strong> ${patternName}</p>
                        <p class="mb-0"><strong>Files to rename:</strong> ${selectedCount}</p>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Current Name</th>
                                    <th>New Name</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${this.currentPreviews.map(preview => `
                                    <tr>
                                        <td><code class="text-muted">${preview.current_name}</code></td>
                                        <td><code class="text-success">${preview.new_full_path}</code></td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                `
            }
            
            const modal = new bootstrap.Modal(this.previewModalTarget)
            modal.show()
        }
    }

    confirmRename() {
        if (this.hasPreviewModalTarget) {
            const modal = bootstrap.Modal.getInstance(this.previewModalTarget)
            modal.hide()
        }
        
        if (this.hasConfirmRenameModalTarget) {
            const modal = new bootstrap.Modal(this.confirmRenameModalTarget)
            modal.show()
        }
    }

    executeRename() {
        const patternId = this.patternSelectTarget.value
        const trackIds = Array.from(document.querySelectorAll('.track-checkbox:checked')).map(cb => cb.value)

        if (!patternId || trackIds.length === 0) {
            alert('Please select a pattern and tracks to rename')
            return
        }

        const formData = new FormData()
        formData.append('pattern_id', patternId)
        trackIds.forEach(id => formData.append('track_ids[]', id))

        // Show loading state
        const renameBtn = this.renameBtnTarget
        const originalText = renameBtn.innerHTML
        renameBtn.disabled = true
        renameBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Renaming...'

        fetch('/file-renaming/rename', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (this.hasConfirmRenameModalTarget) {
                    const modal = bootstrap.Modal.getInstance(this.confirmRenameModalTarget)
                    modal.hide()
                }
                
                // Show success message
                this.showSuccessMessage(data.message)
                
                // Reload tracks to reflect changes
                setTimeout(() => {
                    this.clearTracksTable()
                    this.currentPage = 1
                    this.hasMoreData = true
                    this.loadTracks()
                }, 1000)
            } else {
                this.showErrorMessage(data.error || 'Unknown error occurred')
            }
        })
        .catch(error => {
            console.error('Error:', error)
            this.showErrorMessage('Network error occurred during renaming')
        })
        .finally(() => {
            // Restore button state
            renameBtn.disabled = false
            renameBtn.innerHTML = originalText
        })
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

    analyzeQualityBatch() {
        const checkedBoxes = document.querySelectorAll('.track-checkbox:checked')
        
        if (checkedBoxes.length === 0) {
            alert('Please select tracks first')
            return
        }
        
        if (!confirm(`Analyze quality for ${checkedBoxes.length} tracks?`)) {
            return
        }
        
        const trackIds = Array.from(checkedBoxes).map(cb => cb.value)
        const formData = new FormData()
        trackIds.forEach(id => formData.append('track_ids[]', id))
        
        // Disable button during analysis
        const button = event.target.closest('button')
        const originalText = button.innerHTML
        button.disabled = true
        button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Analyzing...'
        
        fetch('/audio-quality/analyze-batch', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(`Quality analysis completed: ${data.summary.success} successful, ${data.summary.errors} errors`)
                location.reload()
            } else {
                alert('Quality analysis error: ' + data.error)
            }
        })
        .catch(error => {
            console.error('Error:', error)
            alert('Quality analysis error')
        })
        .finally(() => {
            // Restore button
            button.disabled = false
            button.innerHTML = originalText
        })
    }
}
