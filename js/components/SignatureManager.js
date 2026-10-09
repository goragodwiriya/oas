/**
 * SignatureManager - Digital Signature Workflow Management
 * Orchestrates the complete signature process from document preparation to completion
 *
 * Features:
 * - Multi-party signature workflow management
 * - Document routing and approval processes
 * - Signature validation and verification
 * - Real-time status tracking and updates
 * - Integration with AuthManager and SecurityManager
 * - Email notification triggers
 * - Audit trail and compliance logging
 * - API integration for backend operations
 */
class SignatureManager {
  /**
   * Create SignatureManager instance
   * @param {Object} options Configuration options
   */
  constructor(options = {}) {
    this.options = {
      // API endpoints
      endpoints: {
        documents: 'api/signatures/documents',
        workflows: 'api/signatures/workflows',
        sign: 'api/signatures/sign',
        verify: 'api/signatures/verify',
        status: 'api/signatures/status',
        notifications: 'api/signatures/notifications',
        audit: 'api/signatures/audit',
        templates: 'api/signatures/templates'
      },
      // client IP endpoint (can be overridden via options)
      clientIp: 'api/client-ip',

      // Workflow configuration
      workflow: {
        enableMultiParty: true,
        enableSequentialSigning: true,
        enableParallelSigning: true,
        enableDelegation: true,
        enableReminders: true,
        reminderIntervals: [24, 72, 168], // hours
        expirationDays: 30,
        autoExpire: true
      },

      // Signature validation
      validation: {
        enableBiometric: false,
        enableIPTracking: true,
        enableTimestamp: true,
        enableCertificates: false,
        minSignaturePoints: 10,
        maxSignatureAge: 30 * 60 * 1000, // 30 minutes
        enableLocationTracking: false
      },

      // Notification settings
      notifications: {
        email: {
          enabled: true,
          templates: {
            signatureRequest: 'signature-request',
            signatureComplete: 'signature-complete',
            documentComplete: 'document-complete',
            expiration: 'signature-expiration',
            reminder: 'signature-reminder'
          }
        },
        realTime: {
          enabled: true,
          channels: ['websocket', 'sse']
        }
      },

      // Security settings
      security: {
        enableEncryption: true,
        enableAuditTrail: true,
        enableTamperProtection: true,
        enableSecureStorage: true,
        requireAuthentication: true,
        enableIPRestriction: false,
        allowedIPs: []
      },

      // UI settings
      ui: {
        enableProgressBar: true,
        enableSignaturePreview: true,
        enableDocumentPreview: true,
        enableStatusIndicators: true,
        enableParticipantList: true,
        enableComments: true,
        theme: 'default'
      },

      ...options
    };

    // Initialize state
    this.state = {
      currentDocument: null,
      currentWorkflow: null,
      participants: new Map(),
      signatures: new Map(),
      status: 'idle', // idle, preparing, signing, processing, completed, cancelled, expired
      progress: 0,
      errors: [],
      warnings: []
    };

    // Initialize managers
    this.authManager = null;
    this.securityManager = null;
    this.notificationManager = null;
    this.documentViewer = null;
    this.signaturePad = null;
    this.apiService = null;

    // Event handling
    this.events = new EventTarget();
    this.eventHandlers = new Map();

    // Initialize component
    this.init();
  }

  /**
   * Initialize SignatureManager
   */
  async init() {
    try {
      // Get required managers
      this.authManager = Now.getManager('AuthManager');
      this.securityManager = Now.getManager('SecurityManager');
      this.notificationManager = Now.getManager('NotificationManager');

      // Initialize API service
      this.apiService = new SignatureApiService({
        baseUrl: this.options.endpoints.documents.replace('/documents', ''),
        endpoints: this.options.endpoints,
        enableOfflineQueue: true,
        enableCaching: true
      });

      // Validate dependencies
      if (!this.authManager) {
        throw new Error('AuthManager is required for SignatureManager');
      }

      // Setup event listeners
      this.setupEventListeners();

      // Setup security
      await this.initializeSecurity();

      // Mark as initialized
      this.state.initialized = true;
      this.emit('initialized');

    } catch (error) {
      this.handleError('Failed to initialize SignatureManager', error);
    }
  }

  /**
   * Setup event listeners
   */
  setupEventListeners() {
    // Document viewer events
    this.on('documentViewer:signatureFieldAdded', this.handleSignatureFieldAdded.bind(this));
    this.on('documentViewer:signatureFieldRemoved', this.handleSignatureFieldRemoved.bind(this));
    this.on('documentViewer:documentReady', this.handleDocumentReady.bind(this));

    // Signature pad events
    this.on('signaturePad:signatureComplete', this.handleSignatureComplete.bind(this));
    this.on('signaturePad:signatureCleared', this.handleSignatureCleared.bind(this));

    // Auth events
    if (this.authManager) {
      this.authManager.on('userChanged', this.handleUserChanged.bind(this));
      this.authManager.on('logout', this.handleUserLogout.bind(this));
    }

    // Window events
    window.addEventListener('beforeunload', this.handleBeforeUnload.bind(this));
    window.addEventListener('online', this.handleOnline.bind(this));
    window.addEventListener('offline', this.handleOffline.bind(this));
  }

  /**
   * Initialize security measures
   */
  async initializeSecurity() {
    if (!this.securityManager) {
      console.warn('SecurityManager not available, running with limited security');
      return;
    }

    // Enable required security features
    await this.securityManager.enableFeature('inputSanitization');
    await this.securityManager.enableFeature('xssProtection');
    await this.securityManager.enableFeature('csrfProtection');

    if (this.options.security.enableEncryption) {
      await this.securityManager.enableFeature('encryption');
    }

    if (this.options.security.enableAuditTrail) {
      await this.securityManager.enableFeature('auditTrail');
    }
  }

  /**
   * Create new signature workflow
   * @param {Object} documentData Document information
   * @param {Array} participants List of participants
   * @param {Object} workflowOptions Workflow configuration
   * @returns {Promise<Object>} Workflow information
   */
  async createWorkflow(documentData, participants, workflowOptions = {}) {
    try {
      this.setState({status: 'preparing'});

      // Validate inputs
      this.validateDocumentData(documentData);
      this.validateParticipants(participants);

      // Prepare workflow data
      const workflowData = {
        document: documentData,
        participants: participants.map(p => this.prepareParticipant(p)),
        settings: {
          ...this.options.workflow,
          ...workflowOptions
        },
        created: new Date().toISOString(),
        createdBy: this.authManager ? this.authManager.getCurrentUser() : null,
        status: 'draft'
      };

      // Create workflow via API
      const response = await this.apiService.createWorkflow(workflowData);

      if (!response.success) {
        throw new Error(response.error || 'Failed to create workflow');
      }

      // Update state
      this.state.currentWorkflow = response.data;
      this.state.currentDocument = documentData;
      this.updateParticipants(participants);

      // Initialize document viewer
      await this.initializeDocumentViewer(documentData);

      // Emit event
      this.emit('workflowCreated', {workflow: response.data});

      return response.data;

    } catch (error) {
      this.handleError('Failed to create workflow', error);
      throw error;
    }
  }

  /**
   * Send signature request to participants
   * @param {String} workflowId Workflow ID
   * @param {Array} participantIds Participant IDs to send requests to
   * @returns {Promise<Object>} Send results
   */
  async sendSignatureRequests(workflowId, participantIds = null) {
    try {
      this.setState({status: 'signing'});

      const workflow = workflowId || this.state.currentWorkflow?.id;
      if (!workflow) {
        throw new Error('No workflow specified');
      }

      // Determine participants to send to
      const targetParticipants = participantIds ||
        Array.from(this.state.participants.keys()).filter(id => {
          const participant = this.state.participants.get(id);
          return participant.status === 'pending';
        });

      if (targetParticipants.length === 0) {
        throw new Error('No participants to send requests to');
      }

      // Send requests via API
      const response = await this.apiService.sendSignatureRequests(workflow, targetParticipants);

      if (!response.success) {
        throw new Error(response.error || 'Failed to send signature requests');
      }

      // Update participant statuses
      targetParticipants.forEach(participantId => {
        const participant = this.state.participants.get(participantId);
        if (participant) {
          participant.status = 'sent';
          participant.sentAt = new Date().toISOString();
        }
      });

      // Send notifications
      if (this.options.notifications.email.enabled) {
        await this.sendEmailNotifications(targetParticipants, 'signatureRequest');
      }

      // Emit event
      this.emit('signatureRequestsSent', {
        workflow: workflow,
        participants: targetParticipants,
        results: response.data
      });

      return response.data;

    } catch (error) {
      this.handleError('Failed to send signature requests', error);
      throw error;
    }
  }

  /**
   * Process signature for current user
   * @param {Object} signatureData Signature information
   * @param {Object} fieldData Field-specific data
   * @returns {Promise<Object>} Signature result
   */
  async processSignature(signatureData, fieldData) {
    try {
      // Validate signature
      await this.validateSignature(signatureData);

      // Get current user
      const currentUser = this.authManager ? this.authManager.getCurrentUser() : null;
      if (!currentUser) {
        throw new Error('User must be authenticated to sign');
      }

      // Find participant
      const participant = this.findParticipantByUser(currentUser.id);
      if (!participant) {
        throw new Error('User is not a participant in this workflow');
      }

      // Check participant status
      if (participant.status !== 'sent' && participant.status !== 'viewed') {
        throw new Error('Participant is not authorized to sign at this time');
      }

      // Prepare signature data
      const signature = {
        participant: participant.id,
        field: fieldData.id,
        data: signatureData,
        timestamp: new Date().toISOString(),
        ipAddress: await this.getClientIP(),
        userAgent: navigator.userAgent,
        location: this.options.validation.enableLocationTracking ? await this.getLocation() : null,
        biometric: this.options.validation.enableBiometric ? await this.getBiometricData() : null
      };

      // Submit signature via API
      const response = await this.apiService.submitSignature(
        this.state.currentWorkflow.id,
        signature
      );

      if (!response.success) {
        throw new Error(response.error || 'Failed to process signature');
      }

      // Update state
      this.state.signatures.set(fieldData.id, signature);
      participant.status = 'signed';
      participant.signedAt = new Date().toISOString();

      // Update document viewer
      if (this.documentViewer) {
        this.documentViewer.updateSignatureField(fieldData.id, {
          status: 'completed',
          signature: signatureData
        });
      }

      // Check if workflow is complete
      await this.checkWorkflowCompletion();

      // Emit event
      this.emit('signatureProcessed', {
        signature: signature,
        participant: participant,
        field: fieldData
      });

      return response.data;

    } catch (error) {
      this.handleError('Failed to process signature', error);
      throw error;
    }
  }

  /**
   * Validate signature data
   * @param {Object} signatureData Signature to validate
   * @returns {Promise<Boolean>} Validation result
   */
  async validateSignature(signatureData) {
    try {
      // Check minimum points
      if (this.options.validation.minSignaturePoints > 0) {
        const points = signatureData.points || [];
        if (points.length < this.options.validation.minSignaturePoints) {
          throw new Error(`Signature must contain at least ${this.options.validation.minSignaturePoints} points`);
        }
      }

      // Check signature age
      if (this.options.validation.maxSignatureAge > 0) {
        const signatureAge = Date.now() - (signatureData.timestamp || 0);
        if (signatureAge > this.options.validation.maxSignatureAge) {
          throw new Error('Signature has expired, please sign again');
        }
      }

      // Additional validation via security manager
      if (this.securityManager) {
        await this.securityManager.validateSignature(signatureData);
      }

      return true;

    } catch (error) {
      this.handleError('Signature validation failed', error);
      throw error;
    }
  }

  /**
   * Check if workflow is complete
   * @returns {Promise<Boolean>} Completion status
   */
  async checkWorkflowCompletion() {
    try {
      // Check if all required signatures are complete
      const requiredParticipants = Array.from(this.state.participants.values())
        .filter(p => p.required !== false);

      const completedParticipants = requiredParticipants
        .filter(p => p.status === 'signed');

      const isComplete = completedParticipants.length === requiredParticipants.length;

      if (isComplete) {
        await this.completeWorkflow();
      }

      return isComplete;

    } catch (error) {
      this.handleError('Failed to check workflow completion', error);
      return false;
    }
  }

  /**
   * Complete the signature workflow
   * @returns {Promise<Object>} Completion result
   */
  async completeWorkflow() {
    try {
      this.setState({status: 'processing'});

      // Finalize document
      const response = await this.apiService.completeWorkflow(this.state.currentWorkflow.id);

      if (!response.success) {
        throw new Error(response.error || 'Failed to complete workflow');
      }

      // Update state
      this.setState({
        status: 'completed',
        progress: 100
      });

      // Send completion notifications
      if (this.options.notifications.email.enabled) {
        await this.sendCompletionNotifications();
      }

      // Emit event
      this.emit('workflowCompleted', {
        workflow: this.state.currentWorkflow,
        result: response.data
      });

      return response.data;

    } catch (error) {
      this.handleError('Failed to complete workflow', error);
      throw error;
    }
  }

  /**
   * Cancel workflow
   * @param {String} reason Cancellation reason
   * @returns {Promise<Object>} Cancellation result
   */
  async cancelWorkflow(reason = 'Cancelled by user') {
    try {
      if (!this.state.currentWorkflow) {
        throw new Error('No active workflow to cancel');
      }

      const response = await this.apiService.cancelWorkflow(
        this.state.currentWorkflow.id,
        reason
      );

      if (!response.success) {
        throw new Error(response.error || 'Failed to cancel workflow');
      }

      // Update state
      this.setState({
        status: 'cancelled',
        progress: 0
      });

      // Emit event
      this.emit('workflowCancelled', {
        workflow: this.state.currentWorkflow,
        reason: reason
      });

      return response.data;

    } catch (error) {
      this.handleError('Failed to cancel workflow', error);
      throw error;
    }
  }

  /**
   * Get workflow status
   * @param {String} workflowId Workflow ID
   * @returns {Promise<Object>} Status information
   */
  async getWorkflowStatus(workflowId = null) {
    try {
      const workflow = workflowId || this.state.currentWorkflow?.id;
      if (!workflow) {
        throw new Error('No workflow specified');
      }

      const response = await this.apiService.getWorkflow(workflow);

      if (!response.success) {
        throw new Error(response.error || 'Failed to get workflow status');
      }

      // Update local state if it's the current workflow
      if (workflow === this.state.currentWorkflow?.id) {
        this.updateWorkflowState(response.data);
      }

      return response.data;

    } catch (error) {
      this.handleError('Failed to get workflow status', error);
      throw error;
    }
  }

  /**
   * Send email notifications
   * @param {Array} participantIds Participant IDs
   * @param {String} templateType Email template type
   * @returns {Promise<Object>} Send results
   */
  async sendEmailNotifications(participantIds, templateType) {
    try {
      if (!this.options.notifications.email.enabled) {
        return {success: false, message: 'Email notifications are disabled'};
      }

      const participants = participantIds.map(id => this.state.participants.get(id))
        .filter(p => p && p.email);

      if (participants.length === 0) {
        return {success: false, message: 'No participants with email addresses'};
      }

      const response = await this.apiService.sendNotification({
        workflow: this.state.currentWorkflow.id,
        participants: participants.map(p => ({id: p.id, email: p.email})),
        template: templateType,
        data: {
          document: this.state.currentDocument,
          workflow: this.state.currentWorkflow
        }
      });

      return response;

    } catch (error) {
      this.handleError('Failed to send email notifications', error);
      throw error;
    }
  }

  /**
   * Send completion notifications to all participants
   * @returns {Promise<Object>} Send results
   */
  async sendCompletionNotifications() {
    try {
      const allParticipants = Array.from(this.state.participants.keys());
      return await this.sendEmailNotifications(allParticipants, 'documentComplete');
    } catch (error) {
      this.handleError('Failed to send completion notifications', error);
      throw error;
    }
  }

  /**
   * Initialize document viewer
   * @param {Object} documentData Document information
   * @returns {Promise<DocumentViewer>} Document viewer instance
   */
  async initializeDocumentViewer(documentData) {
    try {
      // Create document viewer if it doesn't exist
      if (!this.documentViewer) {
        this.documentViewer = new DocumentViewer({
          enableSignaturePlacement: true,
          enableMultiPartyWorkflow: true,
          signatureManager: this
        });
      }

      // Load document
      await this.documentViewer.loadDocument(documentData);

      // Setup signature fields for current workflow
      if (this.state.currentWorkflow && this.state.currentWorkflow.signatureFields) {
        this.state.currentWorkflow.signatureFields.forEach(field => {
          this.documentViewer.addSignatureField(field);
        });
      }

      return this.documentViewer;

    } catch (error) {
      this.handleError('Failed to initialize document viewer', error);
      throw error;
    }
  }

  /**
   * Initialize signature pad
   * @param {Object} options SignaturePad options
   * @returns {SignaturePad} Signature pad instance
   */
  initializeSignaturePad(options = {}) {
    try {
      if (!this.signaturePad) {
        this.signaturePad = new SignaturePad({
          onComplete: this.handleSignatureComplete.bind(this),
          onClear: this.handleSignatureCleared.bind(this),
          ...options
        });
      }

      return this.signaturePad;

    } catch (error) {
      this.handleError('Failed to initialize signature pad', error);
      throw error;
    }
  }

  // Event handlers
  handleSignatureFieldAdded(event) {
    this.emit('signatureFieldAdded', event.detail);
  }

  handleSignatureFieldRemoved(event) {
    this.emit('signatureFieldRemoved', event.detail);
  }

  handleDocumentReady(event) {
    this.emit('documentReady', event.detail);
  }

  handleSignatureComplete(signatureData) {
    this.emit('signatureComplete', signatureData);
  }

  handleSignatureCleared() {
    this.emit('signatureCleared');
  }

  handleUserChanged(user) {
    // Check if user is authorized for current workflow
    if (this.state.currentWorkflow) {
      const participant = this.findParticipantByUser(user.id);
      if (!participant) {
        this.handleError('User is not authorized for this workflow');
      }
    }
  }

  handleUserLogout() {
    // Clear sensitive data
    this.cleanup();
  }

  handleBeforeUnload(event) {
    if (this.state.status === 'signing' && this.hasUnsavedChanges()) {
      event.preventDefault();
      event.returnValue = 'You have unsaved signature changes. Are you sure you want to leave?';
    }
  }

  handleOnline() {
    // Resume operations when online
    this.emit('online');
  }

  handleOffline() {
    // Handle offline mode
    this.emit('offline');
  }

  handleAuthenticationError() {
    this.handleError('Authentication required');
    if (this.authManager) {
      this.authManager.redirectToLogin();
    }
  }

  // Helper methods
  validateDocumentData(documentData) {
    if (!documentData) {
      throw new Error('Document data is required');
    }
    if (!documentData.id && !documentData.file) {
      throw new Error('Document ID or file is required');
    }
  }

  validateParticipants(participants) {
    if (!Array.isArray(participants) || participants.length === 0) {
      throw new Error('At least one participant is required');
    }

    participants.forEach((participant, index) => {
      if (!participant.email) {
        throw new Error(`Participant ${index + 1} email is required`);
      }
      if (!participant.name) {
        throw new Error(`Participant ${index + 1} name is required`);
      }
    });
  }

  prepareParticipant(participant) {
    return {
      id: participant.id || this.generateId(),
      name: participant.name,
      email: participant.email,
      role: participant.role || 'signer',
      order: participant.order || 0,
      required: participant.required !== false,
      status: 'pending',
      createdAt: new Date().toISOString(),
      ...participant
    };
  }

  updateParticipants(participants) {
    this.state.participants.clear();
    participants.forEach(participant => {
      this.state.participants.set(participant.id, participant);
    });
  }

  findParticipantByUser(userId) {
    return Array.from(this.state.participants.values())
      .find(p => p.userId === userId || p.email === userId);
  }

  updateWorkflowState(workflowData) {
    Object.assign(this.state.currentWorkflow, workflowData);

    // Update participants
    if (workflowData.participants) {
      workflowData.participants.forEach(participant => {
        this.state.participants.set(participant.id, participant);
      });
    }

    // Calculate progress
    this.updateProgress();
  }

  updateProgress() {
    const totalParticipants = this.state.participants.size;
    const completedParticipants = Array.from(this.state.participants.values())
      .filter(p => p.status === 'signed').length;

    this.state.progress = totalParticipants > 0 ?
      Math.round((completedParticipants / totalParticipants) * 100) : 0;
  }

  hasUnsavedChanges() {
    return this.signaturePad && this.signaturePad.hasUnsavedChanges();
  }

  async getClientIP() {
    try {
      const endpoint = (this.options.endpoints && this.options.endpoints.clientIp) ? this.options.endpoints.clientIp : 'api/client-ip';
      const apiService = window.ApiService || window.Now?.getManager?.('api');
      let response;

      if (apiService?.get) {
        response = await apiService.get(endpoint, {}, {headers: {'Accept': 'application/json'}});
      } else if (window.simpleFetch?.get) {
        response = await simpleFetch.get(endpoint, {headers: {'Accept': 'application/json'}});
      } else {
        throw new Error('ApiService is not available');
      }

      if (!response.success) {
        throw new Error(`Request failed (${response.status})`);
      }

      const data = response.data || {};
      EventManager.emit('signature:clientIp:fetched', {endpoint, data});
      return data.ip;
    } catch (error) {
      if (this.options && this.options.validation && this.options.validation.enableIPTracking && this.config?.debug) {
        console.warn('SignatureManager.getClientIP failed:', error);
      }
      return 'unknown';
    }
  }

  async getLocation() {
    return new Promise((resolve) => {
      if (!navigator.geolocation) {
        resolve(null);
        return;
      }

      navigator.geolocation.getCurrentPosition(
        (position) => resolve({
          latitude: position.coords.latitude,
          longitude: position.coords.longitude,
          accuracy: position.coords.accuracy
        }),
        () => resolve(null),
        {timeout: 5000}
      );
    });
  }

  async getBiometricData() {
    // Placeholder for biometric data collection
    return null;
  }

  generateId() {
    return 'sig_' + Date.now().toString(36) + Math.random().toString(36).substr(2);
  }

  setState(newState) {
    Object.assign(this.state, newState);
    this.emit('stateChanged', this.state);
  }

  cleanup() {
    this.state = {
      currentDocument: null,
      currentWorkflow: null,
      participants: new Map(),
      signatures: new Map(),
      status: 'idle',
      progress: 0,
      errors: [],
      warnings: []
    };
  }

  handleError(message, error = null) {
    const errorObj = {
      message,
      error: error ? error.message : null,
      timestamp: new Date().toISOString(),
      stack: error ? error.stack : null
    };

    this.state.errors.push(errorObj);

    if (this.notificationManager) {
      this.notificationManager.error(message);
    } else {
      console.error('SignatureManager Error:', errorObj);
    }

    this.emit('error', errorObj);
  }

  // Event system
  on(event, handler) {
    this.events.addEventListener(event, handler);
    return this;
  }

  off(event, handler) {
    this.events.removeEventListener(event, handler);
    return this;
  }

  emit(event, data = null) {
    this.events.dispatchEvent(new CustomEvent(event, {detail: data}));
    return this;
  }

  // Public API
  getState() {
    return {...this.state};
  }

  getCurrentWorkflow() {
    return this.state.currentWorkflow;
  }

  getCurrentDocument() {
    return this.state.currentDocument;
  }

  getParticipants() {
    return Array.from(this.state.participants.values());
  }

  getSignatures() {
    return Array.from(this.state.signatures.values());
  }

  getProgress() {
    return this.state.progress;
  }

  getStatus() {
    return this.state.status;
  }

  isComplete() {
    return this.state.status === 'completed';
  }

  isActive() {
    return ['preparing', 'signing', 'processing'].includes(this.state.status);
  }

  destroy() {
    // Cleanup
    this.cleanup();

    // Remove event listeners
    window.removeEventListener('beforeunload', this.handleBeforeUnload);
    window.removeEventListener('online', this.handleOnline);
    window.removeEventListener('offline', this.handleOffline);

    // Destroy components
    if (this.documentViewer) {
      this.documentViewer.destroy();
    }
    if (this.signaturePad) {
      this.signaturePad.destroy();
    }
  }
}

// Register with Now.js framework
if (typeof Now !== 'undefined') {
  Now.registerComponent('SignatureManager', SignatureManager);
}

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
  module.exports = SignatureManager;
}
