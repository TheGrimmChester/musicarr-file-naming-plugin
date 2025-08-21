import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = [
        'renameModal', 'patternSelect', 'renameTracksTable', 'renameBtn'
    ]

    static values = {
        albumId: Number,
        albumTitle: String,
        artistId: Number,
        artistName: String,
        tracks: Array
    }

    connect() {
        console.log('🎯 AlbumRename controller connected!')
        console.log('📊 Controller element:', this.element)
        console.log('🔢 Album ID value:', this.albumIdValue)
        console.log('📝 Album title value:', this.albumTitleValue)
        console.log('🎨 Artist ID value:', this.artistIdValue)
        console.log('👤 Artist name value:', this.artistNameValue)
        console.log('🎵 Tracks data value:', this.tracksValue)

        this.patterns = []
        this.currentPreviews = []
        this.handlersInitialized = false

        // Since the modal is rendered via Twig hooks, we need to wait for it
        // and then set up the controller to work with it
        this.waitForModal()
    }

    /**
     * Wait for the modal to be available in the DOM
     */
    waitForModal() {
        const maxAttempts = 50 // 5 seconds max
        let attempts = 0
        
        const checkModal = () => {
            attempts++
            console.log(`🔍 Checking for modal (attempt ${attempts}/${maxAttempts})...`)
            
            // Check if the modal exists in the DOM
            const modal = document.getElementById('renameFilesModal')
            if (modal) {
                console.log('✅ Modal found in DOM, setting up event handlers')
                this.setupEventHandlers()
                return
            }
            
            if (attempts >= maxAttempts) {
                console.error('❌ Modal not found after maximum attempts')
                return
            }
            
            // Try again in 100ms
            setTimeout(checkModal, 100)
        }
        
        // Start checking
        console.log('🚀 Starting modal check...')
        checkModal()
    }

    setupEventHandlers() {
        console.log('🔧 Setting up event handlers...')
        
        // Get modal elements directly from DOM since they're not in controller scope
        this.modalElement = document.getElementById('renameFilesModal')
        this.patternSelectElement = document.getElementById('patternSelect')
        this.renameTracksTableElement = document.getElementById('renameTracksTable')
        this.renameBtnElement = document.getElementById('renameBtn')
        
        if (this.modalElement && this.patternSelectElement && this.renameTracksTableElement && this.renameBtnElement) {
            console.log('✅ All modal elements found, initializing rename handlers')
            this.initializeRenameHandlers()
        } else {
            console.error('❌ Some modal elements not found:', {
                modal: !!this.modalElement,
                patternSelect: !!this.patternSelectElement,
                renameTracksTable: !!this.renameTracksTableElement,
                renameBtn: !!this.renameBtnElement
            })
        }
    }

    /**
     * Check if all required targets are available
     */
    checkTargetsAvailability() {
        const targets = {
            renameModal: this.hasRenameModalTarget,
            patternSelect: this.hasPatternSelectTarget,
            renameTracksTable: this.hasRenameTracksTableTarget,
            renameBtn: this.hasRenameBtnTarget
        }
        
        console.log('🎯 Controller targets check:', targets)
        return Object.values(targets).every(available => available)
    }

    populateRenameTracksTable() {
        console.log('Populating rename tracks table...')
        console.log('Tracks data value:', this.tracksValue)
        console.log('Tracks data length:', this.tracksValue ? this.tracksValue.length : 'undefined')
        
        const tbody = document.getElementById('renameTracksTable')
        if (!tbody) {
            console.error('renameTracksTable tbody not found!')
            return
        }

        tbody.innerHTML = ''
        
        // Wait a bit for tracks data to be loaded if it's still loading
        if (!this.tracksValue || this.tracksValue.length === 0) {
            console.log('No tracks data available, showing empty message')
            tbody.innerHTML = `
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">
                        <i class="fas fa-music fa-2x mb-3"></i>
                        <p>Aucune piste trouvée</p>
                        <small class="text-muted">tracksValue est vide (longueur: ${this.tracksValue ? this.tracksValue.length : 'undefined'})</small>
                        <br>
                        <small class="text-muted">Album ID: ${this.currentAlbumId || this.albumIdValue}</small>
                    </td>
                </tr>
            `
            return
        }
        
        console.log('Populating table with', this.tracksValue.length, 'tracks')
        
        // Filter tracks to only show those with files that need renaming
        const tracksWithFiles = this.tracksValue.filter(track => 
            track.files && track.files.some(file => file.needRename === true)
        )
        
        if (tracksWithFiles.length === 0) {
            console.log('No tracks with files found')
            tbody.innerHTML = `
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">
                        <i class="fas fa-music fa-2x mb-3"></i>
                        <p>Aucune piste avec fichier trouvée</p>
                    </td>
                </tr>
            `
            return
        }
        
        console.log('Populating table with', tracksWithFiles.length, 'tracks with files')
        
        tracksWithFiles.forEach(track => {
            const isChecked = this.trackToPreSelect ? (track.id === this.trackToPreSelect) : true
            const hasFile = track.files && track.files.some(file => file.needRename === true)
            const fileStatus = hasFile ?
                `<span class="badge bg-warning">Nécessite renommage</span>` :
                `<span class="badge bg-success">Déjà correct</span>`
            
            // Generate file list and previews
            let filesHtml = ''
            let previewsHtml = ''
            
            if (track.files && track.files.length > 0) {
                // Only show files that need renaming
                const filesNeedingRename = track.files.filter(file => file.needRename === true)
                
                if (filesNeedingRename.length > 0) {
                    filesNeedingRename.forEach((file, index) => {
                        const fileName = file.filePath || (file.filePath ? file.filePath.split('/').pop() : 'Non disponible')
                        const fileSize = file.fileSize ? this.formatFileSize(file.fileSize) : ''
                        const quality = file.quality ? `<span class="badge bg-info">${file.quality}</span>` : ''
                        const format = file.format ? `<span class="badge bg-secondary">${file.format}</span>` : ''
                        const preferred = file.isPreferred ? `<span class="badge bg-warning">Préféré</span>` : ''
                        
                        filesHtml += `
                            <div class="mb-2 ${file.isPreferred ? 'border-start border-warning ps-2' : ''}">
                                <small class="text-muted">
                                    <i class="fas fa-file-audio me-1"></i>${fileName}
                                    ${fileSize ? ` (${fileSize})` : ''}
                                </small>
                                <div class="mt-1">
                                    ${quality} ${format} ${preferred}
                            </div>
                                </div>
                        `
                        
                        previewsHtml += `
                            <div class="mb-2 ${file.isPreferred ? 'border-start border-warning ps-2' : ''}">
                                <span id="preview-file-${file.id}" class="text-muted small">
                                    <i class="fas fa-eye"></i> Aperçu
                                </span>
                                </div>
                        `
                    })
                } else {
                    filesHtml = '<small class="text-muted">Aucun fichier à renommer</small>'
                    previewsHtml = '<small class="text-muted">Non disponible</small>'
                }
            } else {
                filesHtml = '<small class="text-muted">Aucun fichier</small>'
                previewsHtml = '<small class="text-muted">Non disponible</small>'
            }
            
            const row = document.createElement('tr')
            row.innerHTML = `
                <td>
                    <input type="checkbox" class="form-check-input track-checkbox" 
                           value="${track.id}" ${hasFile ? (isChecked ? 'checked' : '') : 'disabled'}>
                </td>
                <td>${track.trackNumber || '--'}</td>
                <td>
                    <strong>${track.title}</strong>
                    <br><small class="text-muted">${fileStatus} (${track.files ? track.files.length : 0} fichier${track.files && track.files.length !== 1 ? 's' : ''})</small>
                </td>
                <td>
                    <div class="files-list">
                        ${filesHtml}
                        </div>
                </td>
                <td>
                    <div class="previews-list">
                        ${previewsHtml}
                        </div>
                </td>
            `
            
            tbody.appendChild(row)
        })
        
        this.initializeRenameHandlers()
    }

    initializeRenameHandlers() {
        const patternSelect = this.patternSelectElement
        const selectAllCheckbox = document.getElementById('selectAllCheckbox')
        const trackCheckboxes = document.querySelectorAll('.track-checkbox')
        const renameBtn = this.renameBtnElement

        if (!patternSelect || !renameBtn) return

        // Handle "Select All" checkbox
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', (event) => {
                trackCheckboxes.forEach(checkbox => {
                    if (!checkbox.disabled) {
                        checkbox.checked = event.target.checked
                    }
                })
                this.updateSelectAllCheckboxState()
                this.updateSelection()
            })
        }

        // Handle individual track checkboxes
        trackCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', () => {
                this.updateSelectAllCheckboxState()
                this.updateSelection()
                // Regenerate preview if pattern is selected
                if (patternSelect.value !== '') {
                    this.generatePreview()
                }
            })
        })

        // Handle pattern selection change
        patternSelect.addEventListener('change', () => {
            this.updateSelection()
            // Regenerate preview if tracks are selected
            const checkedBoxes = document.querySelectorAll('.track-checkbox:checked')
            if (checkedBoxes.length > 0) {
                this.generatePreview()
            }
        })
    }

    updateSelection() {
        const patternSelect = this.patternSelectElement
        const renameBtn = this.renameBtnElement
        
        if (!patternSelect || !renameBtn) return
        
        const checkedBoxes = document.querySelectorAll('.track-checkbox:checked')
        const hasSelection = checkedBoxes.length > 0
        const hasPattern = patternSelect.value !== ''

        renameBtn.disabled = !hasSelection || !hasPattern
    }

    updateSelectAllCheckboxState() {
        const selectAllCheckbox = document.getElementById('selectAllCheckbox')
        const selectAllTracksCheckbox = document.getElementById('selectAllTracks')
        
        if (selectAllCheckbox) {
            const checkedCheckboxes = document.querySelectorAll('#renameTracksTable input[type="checkbox"]:checked')
            const totalCheckboxes = document.querySelectorAll('#renameTracksTable input[type="checkbox"]')
            
            selectAllCheckbox.checked = checkedCheckboxes.length === totalCheckboxes.length
            selectAllCheckbox.indeterminate = checkedCheckboxes.length > 0 && checkedCheckboxes.length < totalCheckboxes.length
        }
        
        if (selectAllTracksCheckbox) {
            const checkedCheckboxes = document.querySelectorAll('#renameTracksTable input[type="checkbox"]:checked')
            const totalCheckboxes = document.querySelectorAll('#renameTracksTable input[type="checkbox"]')
            
            selectAllTracksCheckbox.checked = checkedCheckboxes.length === totalCheckboxes.length
        }
    }

    generatePreview() {
        if (!this.patternSelectElement) return
        
        const selectedPatternId = this.patternSelectElement.value
        
        if (!selectedPatternId) {
            this.showAlert('warning', 'Veuillez sélectionner un pattern de nommage')
            return
        }

        // Get selected tracks
        const trackIds = Array.from(document.querySelectorAll('.track-checkbox:checked')).map(cb => parseInt(cb.value))
        
        console.log('🎯 Selected track IDs:', trackIds)
        console.log('📊 All tracks data:', this.tracksValue)
        
        if (trackIds.length === 0) {
            this.showAlert('warning', 'Veuillez sélectionner au moins une piste')
            return
        }

        // Filter only tracks with files for preview
        const tracksWithFiles = this.tracksValue.filter(track => {
            const hasId = trackIds.includes(track.id)
            const hasFiles = track.files && track.files.length > 0
            console.log(`🔍 Track ${track.id} (${track.title}): hasId=${hasId}, hasFiles=${hasFiles}, files=${track.files?.length || 0}`)
            return hasId && hasFiles
        })

        console.log(`✅ Found ${tracksWithFiles.length} tracks with files`)
        
        if (tracksWithFiles.length === 0) {
            this.showAlert('warning', 'Aucune piste avec fichier sélectionnée')
            return
        }

        // Collect track file IDs for the preview endpoint (only files that need renaming)
        const trackFileIds = []
        tracksWithFiles.forEach(track => {
            if (track.files) {
                track.files.forEach(file => {
                    if (file.needRename === true) {
                        console.log(`📁 Adding track file ID: ${file.id} for ${file.filePath} (needs renaming)`)
                        trackFileIds.push(file.id)
                    } else {
                        console.log(`⏭️ Skipping track file ID: ${file.id} for ${file.filePath} (no renaming needed)`)
                    }
                })
            }
        })

        console.log(`📦 Total track file IDs collected: ${trackFileIds.length}`)
        this.generateRenamePreview(selectedPatternId, trackFileIds)
    }

    getSelectedTracksForRenaming() {
        const checkboxes = document.querySelectorAll('#renameTracksTable input[type="checkbox"]:checked')
        const selectedTracks = []
        
        checkboxes.forEach(checkbox => {
            const trackId = parseInt(checkbox.value)
            const track = this.tracksValue.find(t => t.id === trackId)
            if (track) {
                selectedTracks.push(track)
            }
        })
        
        return selectedTracks
    }

    async generateRenamePreview(patternId, trackFileIds) {
        try {
            console.log('generateRenamePreview called with:', {
                patternId: patternId,
                trackFileIdsCount: trackFileIds.length,
                trackFileIds: trackFileIds
            })
            
            if (trackFileIds.length === 0) {
                this.showAlert('warning', 'Aucun fichier trouvé pour ces pistes')
                return
            }

            const formData = new FormData()
            formData.append('pattern_id', patternId)
            trackFileIds.forEach(fileId => {
                formData.append('track_ids[]', fileId)
            })

            console.log('Sending preview request to /file-renaming/preview')
            const response = await fetch('/file-renaming/preview', {
                method: 'POST',
                body: formData
            })

            console.log('Preview response status:', response.status)
            const result = await response.json()
            console.log('Preview response data:', result)
            
            // Detailed logging of the response
            if (result.success && result.previews) {
                console.log('🔍 Detailed preview analysis:')
                result.previews.forEach((preview, index) => {
                    console.log(`  Preview ${index + 1}:`)
                    console.log(`    - ID: ${preview.id}`)
                    console.log(`    - Current name: ${preview.current_name}`)
                    console.log(`    - New name: ${preview.new_name}`)
                    console.log(`    - New full path: ${preview.new_full_path}`)
                    console.log(`    - Track: ${preview.title} by ${preview.artist}`)
                })
            }

            if (result.success) {
                this.displayRenamePreview(result.previews)
                this.enableRenameButton()
            } else {
                console.error('Preview generation failed:', result.error)
                this.showAlert('danger', result.error || 'Erreur lors de la génération de l\'aperçu')
            }
        } catch (error) {
            console.error('Error generating preview:', error)
            this.showAlert('danger', 'Erreur lors de la génération de l\'aperçu')
        }
    }

    displayRenamePreview(previews) {
        console.log('displayRenamePreview called with previews:', previews)
        
        // First, hide all previews
        const allPreviewElements = document.querySelectorAll('[id^="preview-file-"]')
        console.log('Found preview elements:', allPreviewElements.length)
        allPreviewElements.forEach(element => {
            element.innerHTML = '<i class="fas fa-eye"></i> Aperçu'
            element.style.display = 'block'
        })

        // Then, show only the previews that exist
        previews.forEach(preview => {
            console.log('🔍 Processing preview:', preview)
            console.log('🔍 Preview keys:', Object.keys(preview))
            
            // The preview endpoint returns 'id' which is the TrackFile ID
            const fileId = preview.id || preview.file_id
            console.log('🔍 Looking for element with ID:', `preview-file-${fileId}`)
            
            const previewElement = document.getElementById(`preview-file-${fileId}`)
            
            if (previewElement) {
                console.log('✅ Found preview element, updating with:', preview.new_full_path)
                console.log('📝 Current preview element content BEFORE update:', previewElement.innerHTML)
                previewElement.innerHTML = `<code class="text-success preview-item">${preview.new_full_path}</code>`
                console.log('📝 Current preview element content AFTER update:', previewElement.innerHTML)
            } else {
                console.log('❌ Preview element not found for fileId:', fileId)
                console.log('📋 Available preview elements:')
                document.querySelectorAll('[id^="preview-file-"]').forEach(el => {
                    console.log(`  - ${el.id}: "${el.innerHTML}"`)
                })
            }
        })

        // Hide empty previews (those without preview)
        document.querySelectorAll('[id^="preview-file-"]').forEach(element => {
            if (element.innerHTML === '<i class="fas fa-eye"></i> Aperçu') {
                element.style.display = 'none'
            }
        })

        // Hide completely rows that have no visible preview
        document.querySelectorAll('#renameTracksTable tr').forEach(row => {
            const previewElements = row.querySelectorAll('[id^="preview-file-"]')
            const hasVisiblePreview = Array.from(previewElements).some(element =>
                element.style.display !== 'none' && element.innerHTML !== '<i class="fas fa-eye"></i> Aperçu'
            )

            if (previewElements.length > 0 && !hasVisiblePreview) {
                row.style.display = 'none'
            } else {
                row.style.display = ''
            }
        })

        // Show message if no rows are visible
        const visibleRows = document.querySelectorAll('#renameTracksTable tr:not([style*="display: none"])')
        const noFilesMessage = document.getElementById('noFilesMessage')

        if (visibleRows.length === 0 && previews.length > 0) {
            if (!noFilesMessage) {
                const tbody = document.getElementById('renameTracksTable')
                const messageRow = document.createElement('tr')
                messageRow.id = 'noFilesMessage'
                messageRow.innerHTML = `
                    <td colspan="5" class="text-center text-muted py-4">
                        <i class="fas fa-check-circle fa-2x mb-3"></i>
                        <p class="mb-0">Tous les fichiers ont déjà le bon format !</p>
                        <small>Aucun renommage nécessaire.</small>
                    </td>
                `
                tbody.appendChild(messageRow)
            }
        } else if (noFilesMessage) {
            noFilesMessage.remove()
        }
    }

    enableRenameButton() {
        if (this.renameBtnElement) {
            this.renameBtnElement.disabled = false
        }
    }

    toggleAllTracks(event) {
        const checked = event.currentTarget.checked
        const checkboxes = document.querySelectorAll('#renameTracksTable input[type="checkbox"]')
        
        checkboxes.forEach(checkbox => {
            checkbox.checked = checked
        })
        
        // Update the other select all checkbox state
        this.updateSelectAllCheckboxState()
        
        // Add event listeners to individual checkboxes
        this.addTrackCheckboxListeners()
    }

    addTrackCheckboxListeners() {
        const checkboxes = document.querySelectorAll('#renameTracksTable input[type="checkbox"]')
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', () => {
                this.updateSelectAllCheckboxState()
            })
        })
    }

    async renameFiles() {
        if (!this.patternSelectElement) return
        
        const selectedPatternId = this.patternSelectElement.value
        
        if (!selectedPatternId) {
            this.showAlert('warning', 'Veuillez sélectionner un pattern de nommage')
            return
        }

        const selectedTracks = this.getSelectedTracksForRenaming()
        if (selectedTracks.length === 0) {
            this.showAlert('warning', 'Aucune piste sélectionnée')
            return
        }

        // Get track file IDs for the selected tracks
        const trackFileIds = []
        selectedTracks.forEach(track => {
            if (track.files) {
                track.files.forEach(file => {
                    trackFileIds.push(file.id)
                })
            }
        })

        if (trackFileIds.length === 0) {
            this.showAlert('warning', 'Aucun fichier trouvé pour ces pistes')
            return
        }

        try {
            const response = await fetch('/file-renaming/rename', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    'pattern_id': selectedPatternId,
                    'track_ids': trackFileIds
                })
            })

            const result = await response.json()

            if (result.success) {
                this.showAlert('success', result.message || 'Fichiers renommés avec succès')
                // Refresh the tracks data
                this.refreshTracksData()
            } else {
                this.showAlert('danger', result.error || 'Erreur lors du renommage')
            }
        } catch (error) {
            console.error('Error renaming files:', error)
            this.showAlert('danger', 'Erreur lors du renommage')
        }
    }

    refreshTracksData() {
        // This method should be implemented to refresh the tracks data
        // after successful renaming
        console.log('Refreshing tracks data after rename...')
        // You can implement this based on your needs
    }

    formatFileSize(bytes) {
        if (!bytes || bytes === 0) return '0 B'
        const k = 1024
        const sizes = ['B', 'KB', 'MB', 'GB']
        const i = Math.floor(Math.log(bytes) / Math.log(k))
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
    }

    showAlert(type, message) {
        // Create a temporary alert
        const alertDiv = document.createElement('div')
        alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed`
        alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;'
        alertDiv.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'warning' ? 'exclamation-triangle' : 'exclamation-circle'} me-2"></i>
            <strong>${type === 'success' ? 'Succès!' : type === 'warning' ? 'Attention!' : 'Erreur!'}</strong> ${message}
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

    // File renaming functionality
    openRenameModal() {
        console.log('🚀 openRenameModal called!')
        console.log('📊 Current controller state:', {
            albumId: this.albumIdValue,
            albumTitle: this.albumTitleValue,
            artistId: this.artistIdValue,
            artistName: this.artistNameValue,
            tracks: this.tracksValue
        })
        
        // Check if the modal exists in the DOM
        const modal = document.getElementById('renameFilesModal')
        if (!modal) {
            console.error('❌ renameFilesModal not found in DOM - cannot open modal')
            alert('Rename modal not available. Please refresh the page and try again.')
            return
        }
        
        // Check if we have the required elements
        if (!this.modalElement || !this.patternSelectElement || !this.renameTracksTableElement || !this.renameBtnElement) {
            console.error('❌ Modal elements not initialized - cannot open modal')
            alert('Rename modal not properly initialized. Please refresh the page and try again.')
            return
        }
        
        // Initialize handlers if not done yet
        if (!this.handlersInitialized) {
            console.log('🔄 Initializing handlers now...')
            this.initializeRenameHandlers()
            this.handlersInitialized = true
        }
        
        // Show the rename modal
        this.showRenameModal()
    }

    showRenameModal() {
        console.log('showRenameModal called')
        
        // Reload tracks data from the backend
        this.reloadTracksData()
        
        // Load patterns and populate the select
        this.loadPatternsForRenaming()
        
        // Populate tracks table
        this.populateRenameTracksTable()
        
        // Show the existing template modal
        const modalElement = document.getElementById('renameFilesModal')
        console.log('Modal element found:', modalElement)
        
        const modal = bootstrap.Modal.getInstance(modalElement) || new bootstrap.Modal(modalElement)
        modal.show()

        // Note: Preview will be generated automatically after patterns are loaded
        // This ensures proper timing instead of relying on setTimeout
        console.log('📝 Modal shown, patterns will be loaded and preview generated automatically')
    }

    async loadPatternsForRenaming() {
        try {
            console.log('Loading patterns for renaming...')
            console.log('Has patternSelectTarget:', this.hasPatternSelectTarget)
            console.log('PatternSelectTarget element:', this.patternSelectElement)
            
            const response = await fetch('/file-naming-patterns/api/list')
            console.log('Patterns API response status:', response.status)
            console.log('Patterns API response headers:', response.headers)
            
            const data = await response.json()
            console.log('Patterns API response data:', data)
            console.log('Patterns API response data.patterns:', data.patterns)
            console.log('Patterns API response data.patterns type:', typeof data.patterns)
            console.log('Patterns API response data.patterns isArray:', Array.isArray(data.patterns))

            if (data.success && data.patterns && Array.isArray(data.patterns) && data.patterns.length > 0) {
                // Store patterns globally for compatibility with old template
                this.patterns = data.patterns
                console.log('✅ Patterns loaded successfully:', this.patterns.length, 'patterns')
                console.log('📋 Pattern details:', this.patterns)
                
                if (this.patternSelectElement) {
                    this.patternSelectElement.innerHTML = '<option value="">Sélectionner un pattern</option>'
                    
                    this.patterns.forEach(pattern => {
                        const option = document.createElement('option')
                        option.value = pattern.id
                        option.textContent = `${pattern.name} - ${pattern.pattern}`
                        this.patternSelectElement.appendChild(option)
                    })

                    // Automatically select the first pattern if it exists
                    if (this.patterns.length > 0) {
                        console.log('🎯 Auto-selecting first pattern:', this.patterns[0].name)
                        this.patternSelectElement.selectedIndex = 1 // Index 1 = first pattern (index 0 = placeholder)
                        this.updateSelection()
                        
                        // Generate preview now that patterns are loaded
                        console.log('🔄 Generating preview after patterns loaded...')
                        this.generatePreview()
                    }
                } else {
                    console.error('Pattern select target not found!')
                }
            } else {
                console.log('🔄 No patterns available or invalid data, adding fallback patterns...')
                console.log('Data success:', data.success)
                console.log('Data patterns:', data.patterns)
                console.log('Data patterns length:', data.patterns?.length)
                
                // Add fallback patterns when API fails or returns empty patterns
                this.patterns = [
                    { id: 'fallback1', name: 'Default Pattern', pattern: '{artist}/{album}/{track_number} - {title}' },
                    { id: 'fallback2', name: 'Simple Pattern', pattern: '{track_number} - {title}' }
                ]
                
                if (this.patternSelectElement) {
                    this.patternSelectElement.innerHTML = '<option value="">Sélectionner un pattern</option>'
                    
                    this.patterns.forEach(pattern => {
                        const option = document.createElement('option')
                        option.value = pattern.id
                        option.textContent = `${pattern.name} - ${pattern.pattern}`
                        this.patternSelectElement.appendChild(option)
                    })

                    // Auto-select first fallback pattern
                    console.log('🎯 Auto-selecting fallback pattern')
                    this.patternSelectElement.selectedIndex = 1
                    
                    // Force update selection and enable button
                    console.log('🔄 Updating selection after fallback pattern selection...')
                    this.updateSelection()
                    
                    // Also force enable the button directly
                    if (this.renameBtnElement) {
                        console.log('🔧 Force enabling rename button...')
                        this.renameBtnElement.disabled = false
                    }
                    
                    // Generate preview
                    console.log('🔄 Generating preview with fallback pattern...')
                    this.generatePreview()
                }
            }
        } catch (error) {
            console.error('Error loading patterns:', error)
            console.log('🔄 API call failed, adding fallback patterns...')
            
            // Add fallback patterns when API completely fails
            this.patterns = [
                { id: 'fallback1', name: 'Default Pattern', pattern: '{artist}/{album}/{track_number} - {title}' },
                { id: 'fallback2', name: 'Simple Pattern', pattern: '{track_number} - {title}' }
            ]
            
            if (this.patternSelectElement) {
                this.patternSelectElement.innerHTML = '<option value="">Sélectionner un pattern</option>'
                
                this.patterns.forEach(pattern => {
                    const option = document.createElement('option')
                    option.value = pattern.id
                    option.textContent = `${pattern.name} - ${pattern.pattern}`
                    this.patternSelectElement.appendChild(option)
                })

                // Auto-select first fallback pattern
                console.log('🎯 Auto-selecting fallback pattern after API error')
                this.patternSelectElement.selectedIndex = 1
                
                // Force update selection and enable button
                console.log('🔄 Updating selection after fallback pattern selection (API error)...')
                this.updateSelection()
                
                // Also force enable the button directly
                if (this.renameBtnElement) {
                    console.log('🔧 Force enabling rename button after API error...')
                    this.renameBtnElement.disabled = false
                }
                
                // Generate preview
                console.log('🔄 Generating preview with fallback pattern after API error...')
                this.generatePreview()
            }
        }
    }

    // Individual track renaming - opens the bulk rename modal with the track pre-selected
    openRenameModalForTrack(event) {
        const trackId = event.currentTarget.dataset.trackId
        const trackTitle = event.currentTarget.dataset.trackTitle
        
        // Store the track to pre-select
        this.trackToPreSelect = trackId
        
        // Open the rename modal
        this.showRenameModal()
    }

    // Individual track renaming - directly calls the controller to rename the track
    async renameTrack(event) {
        const trackId = event.currentTarget.dataset.trackId
        const trackTitle = event.currentTarget.dataset.trackTitle
        
        if (!confirm(`Voulez-vous renommer la piste "${trackTitle}" ?`)) {
            return
        }
        
        // Store button reference at the beginning to avoid null reference issues
        const button = event.currentTarget
        const originalContent = button.innerHTML
        
        try {
            // Show loading state
            button.disabled = true
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'
            
            // Call the FileRenamingController to rename this specific track
            const requestBody = {
                track_ids: [trackId],
                pattern_id: null // Use default pattern for individual track renaming
            }
            
            console.log('Sending rename request:', {
                url: '/file-renaming/rename-track',
                method: 'POST',
                body: requestBody
            })
            
            const response = await fetch('/file-renaming/rename-track', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(requestBody)
            })
            
            if (response.ok) {
                const result = await response.json()
                if (result.success) {
                    this.showAlert('success', `Piste "${trackTitle}" renommée avec succès`)
                    // Refresh the page to show updated track information
                    setTimeout(() => {
                        window.location.reload()
                    }, 1500)
                } else {
                    this.showAlert('danger', `Erreur lors du renommage: ${result.message || 'Erreur inconnue'}`)
                }
            } else {
                const errorText = await response.text()
                console.error('Server error response:', errorText)
                this.showAlert('danger', `Erreur lors du renommage de la piste: ${response.status} ${response.statusText}`)
            }
        } catch (error) {
            console.error('Error renaming track:', error)
            this.showAlert('danger', 'Erreur lors du renommage de la piste')
        } finally {
            // Restore button state using stored reference
            if (button && button.parentNode) {
                button.disabled = false
                button.innerHTML = originalContent
            }
        }
    }

    // Method to reload tracks data from backend
    reloadTracksData() {
        // This method should be implemented to reload tracks data
        // You can implement this based on your needs
        console.log('Reloading tracks data...')
        // You can implement this based on your needs
    }
}
