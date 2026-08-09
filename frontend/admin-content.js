const contentImportState = {
  payload: null,
  preview: null,
};

function contentElement(id) {
  return document.getElementById(id);
}

function contentSetText(id, value) {
  const node = contentElement(id);
  if (node) node.textContent = value == null ? '' : String(value);
}

function contentMetadata() {
  const sourceYear = contentElement('contentSourceYear')?.value.trim() || '';
  return {
    source_type: contentElement('contentSourceType')?.value || '',
    source_title: contentElement('contentSourceTitle')?.value.trim() || '',
    exam_code: contentElement('contentExamCode')?.value.trim() || '',
    source_year: sourceYear === '' ? null : Number(sourceYear),
    publisher: contentElement('contentPublisher')?.value.trim() || '',
    source_reference: contentElement('contentSourceReference')?.value.trim() || '',
    trust_level: contentElement('contentTrustLevel')?.value || '',
    ownership_confirmed: contentElement('contentOwnershipConfirmed')?.checked === true,
  };
}

async function contentFilePayload(file, metadata) {
  const extension = file.name.toLowerCase().split('.').pop();
  if (extension === 'md') {
    return {
      filename: file.name,
      content: await file.text(),
      metadata,
    };
  }

  const buffer = await file.arrayBuffer();
  const bytes = new Uint8Array(buffer);
  let binary = '';
  const chunkSize = 0x8000;
  for (let index = 0; index < bytes.length; index += chunkSize) {
    binary += String.fromCharCode(...bytes.subarray(index, index + chunkSize));
  }

  return {
    filename: file.name,
    content_base64: btoa(binary),
    metadata,
  };
}

function contentWarningIsBlocking(warning) {
  return [
    'english_only_content_required',
    'unrecognized_subject',
    'no_syllabus_records',
    'missing_subject_weight',
  ].includes(warning?.code);
}

function renderContentPreview(preview) {
  const summary = preview?.summary || {};
  const warningList = contentElement('contentImportWarnings');
  const recordList = contentElement('contentImportRecords');
  const publishButton = contentElement('contentPublishButton');

  contentSetText(
    'contentImportSummary',
    String(summary.total_records || 0) + ' records • ' +
    String(summary.valid_records || 0) + ' valid • ' +
    String(summary.invalid_records || 0) + ' invalid • ' +
    String(summary.language_warning_records || 0) + ' language warnings'
  );

  if (warningList) {
    warningList.replaceChildren();
    const warnings = Array.isArray(preview?.warnings) ? preview.warnings : [];
    if (!warnings.length) {
      const item = document.createElement('li');
      item.textContent = 'No parser warnings.';
      warningList.appendChild(item);
    } else {
      warnings.slice(0, 30).forEach((warning) => {
        const item = document.createElement('li');
        item.textContent = 'Line ' + (warning.line || '?') + ' · ' +
          (warning.code || 'warning') + ' · ' + (warning.message || '');
        warningList.appendChild(item);
      });
      if (warnings.length > 30) {
        const item = document.createElement('li');
        item.textContent = 'Showing 30 of ' + warnings.length + ' warnings.';
        warningList.appendChild(item);
      }
    }
  }

  if (recordList) {
    recordList.replaceChildren();
    const records = Array.isArray(preview?.records) ? preview.records : [];
    records.slice(0, 12).forEach((record) => {
      const card = document.createElement('article');
      card.className = 'content-record-preview';

      const heading = document.createElement('strong');
      heading.textContent = record.record_type === 'question'
        ? 'Q' + (record.question_number || '?') + ' · ' + (record.subject_code || 'Unmapped')
        : (record.record_type || 'record') + ' · ' + (record.code || '');
      card.appendChild(heading);

      const title = document.createElement('p');
      title.textContent = record.record_type === 'question'
        ? record.prompt_en || 'Missing question text'
        : record.title_en || '';
      card.appendChild(title);

      const errors = Array.isArray(record.validation_errors) ? record.validation_errors : [];
      if (errors.length || record.language_warning) {
        const flag = document.createElement('span');
        flag.className = 'content-record-flag';
        flag.textContent = errors.concat(record.language_warning || []).join(', ');
        card.appendChild(flag);
      }
      recordList.appendChild(card);
    });
    if (records.length > 12) {
      const more = document.createElement('p');
      more.className = 'content-preview-more';
      more.textContent = 'Showing 12 of ' + records.length + ' records.';
      recordList.appendChild(more);
    }
  }

  const warnings = Array.isArray(preview?.warnings) ? preview.warnings : [];
  const hasBlockingWarning = warnings.some(contentWarningIsBlocking);
  const canPublish = Boolean(
    contentImportState.payload &&
    summary.total_records > 0 &&
    summary.invalid_records === 0 &&
    summary.language_warning_records === 0 &&
    !hasBlockingWarning &&
    ['owner_verified', 'reviewer_verified'].includes(preview?.source?.trust_level)
  );
  if (publishButton) publishButton.disabled = !canPublish;
}

async function previewContentDocument() {
  const file = contentElement('contentFile')?.files?.[0];
  if (!file) {
    window.showToast?.('Choose a Markdown or Word file first.', 'error');
    return;
  }

  const metadata = contentMetadata();
  if (!metadata.source_title || !metadata.exam_code || !metadata.ownership_confirmed) {
    window.showToast?.('Complete the source title, exam code, and ownership confirmation.', 'error');
    return;
  }

  const previewButton = contentElement('contentPreviewButton');
  const status = contentElement('contentImportStatus');
  if (previewButton) previewButton.disabled = true;
  if (status) status.textContent = 'Reading and validating document...';

  try {
    const payload = await contentFilePayload(file, metadata);
    const response = await window.apiFetch('/api/admin-content-preview.php', 'POST', payload);
    contentImportState.payload = payload;
    contentImportState.preview = response.preview;
    renderContentPreview(response.preview);
    if (status) status.textContent = 'Preview ready. Review warnings before publishing.';
    window.showToast?.('Content preview generated.', 'success');
  } catch (error) {
    contentImportState.payload = null;
    contentImportState.preview = null;
    renderContentPreview({ summary: {}, warnings: [], records: [] });
    if (status) status.textContent = error.message || 'Preview failed.';
    window.showToast?.(error.message || 'Preview failed.', 'error');
  } finally {
    if (previewButton) previewButton.disabled = false;
  }
}

async function publishContentDocument() {
  if (!contentImportState.payload || !contentImportState.preview) {
    window.showToast?.('Generate a valid preview first.', 'error');
    return;
  }

  if (!window.confirm('Publish this validated content to the canonical study database?')) {
    return;
  }

  const publishButton = contentElement('contentPublishButton');
  const status = contentElement('contentImportStatus');
  if (publishButton) publishButton.disabled = true;
  if (status) status.textContent = 'Publishing validated records...';

  try {
    const response = await window.apiFetch('/api/admin-content-publish.php', 'POST', {
      ...contentImportState.payload,
      confirm_publish: true,
    });
    if (status) status.textContent = response.message + ' Imported ' + response.imported_records + ' records.';
    window.showToast?.('Content published successfully.', 'success');
  } catch (error) {
    if (status) status.textContent = error.message || 'Publish failed.';
    window.showToast?.(error.message || 'Publish failed.', 'error');
  } finally {
    if (contentImportState.preview) renderContentPreview(contentImportState.preview);
  }
}

document.addEventListener('click', (event) => {
  const trigger = event.target.closest('[data-admin-action]');
  if (!trigger) return;
  if (trigger.dataset.adminAction === 'preview-content') previewContentDocument();
  if (trigger.dataset.adminAction === 'publish-content') publishContentDocument();
});

document.addEventListener('change', (event) => {
  if (event.target.id !== 'contentFile') return;
  contentImportState.payload = null;
  contentImportState.preview = null;
  contentSetText('contentImportStatus', 'Ready for preview.');
  contentSetText('contentImportSummary', 'No document previewed.');
  const publishButton = contentElement('contentPublishButton');
  if (publishButton) publishButton.disabled = true;
});