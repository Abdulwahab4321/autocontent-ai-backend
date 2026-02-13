// includes/Extensions/js/image-integration.js
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        // Show/hide custom prompt input
        const promptCheckbox = document.getElementById('aab_get_image_by_prompt');
        const promptRow = document.getElementById('aab_custom_prompt_row');
        function togglePromptRow() {
            if (!promptRow) return;
            promptRow.style.display = promptCheckbox && promptCheckbox.checked ? '' : 'none';
        }
        if (promptCheckbox) {
            promptCheckbox.addEventListener('change', togglePromptRow);
            togglePromptRow();
        }

        // Ensure number-of-images stays within 1..3
        const numSel = document.getElementById('aab_content_num_images');
        if (numSel) {
            numSel.addEventListener('change', function () {
                let v = parseInt(this.value) || 1;
                if (v < 1) v = 1;
                if (v > 3) v = 3;
                this.value = v;
            });
        }

        // Ensure "Generate Featured Image" toggles size field visibility (optional UX)
        const featGenerate = document.getElementById('aab_feat_generate');
        function toggleFeatFields() {
            const featSize = document.getElementById('aab_feat_image_size');
            const featMethod = document.getElementById('aab_feat_image_method');
            if (!featSize || !featMethod) return;
            if (featGenerate && !featGenerate.checked) {
                featSize.disabled = true;
                featMethod.disabled = true;
            } else {
                featSize.disabled = false;
                featMethod.disabled = false;
            }
        }
        if (featGenerate) {
            featGenerate.addEventListener('change', toggleFeatFields);
            toggleFeatFields();
        }
    });
})();
