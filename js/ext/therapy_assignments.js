$(document).ready(function() {
    $('#save-therapy-assignments').on('click', function() {
        const basePath = $(this).data('basePath');
        const targetUserId = $(this).data('targetUserId');
        const selectedGroups = $('#therapy_assigned_groups_select').val() || [];

        const formData = new FormData();
        formData.append('targetUserId', targetUserId);
        selectedGroups.forEach(groupId => formData.append('selectedGroupIds[]', groupId));

        fetch(basePath + '/request/AjaxTherapyChat/saveTherapistAssignments', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                $.alert({
                    "title":"Success",
                    "content":"Assignments saved successfully!",
                    "type":"success"
                });
            } else {
                $.alert({
                    "title":"Error",
                    "content":"Error saving assignments: " + (data.error || 'Unknown error'),
                    "type":"error"
                });
            }
        })
        .catch(error => {
            console.error('Error:', error);
            $.alert({
                "title":"Error",
                "content":"An error occurred while saving assignments.",
                "type":"error"
            });
        });
    });
});
