(function($) {
    'use strict';

    $('#test_connection').on('click', function() {
        $.ajax({
            type: 'POST',
            url: 'admin-post.php',
            data: {
                action: 's3_test_connection',
                nonce: wps3_ajax.nonce
            }
        }).then(function(response) {
            if (response !== 'OK') {
                alert(response);
            } else {
                alert('Test successfully.');
            }
        });
    });

    $('#start_batch_migration').on('click', function() {
        const step = Math.round(100 / $('#migration_log').data('count'));
        let currentPercent = 0;

        function updateProgressBar() {
            $('#progress_bar_bar').css('width', currentPercent + '%');
            $('#progress_bar_percent').text(currentPercent + ' %');
        }

        function batchSync() {
            $.ajax({
                type: 'POST',
                url: 'admin-post.php',
                dataType: 'json',
                data: {
                    action: 's3_sync_batch',
                    nonce: wps3_ajax.nonce
                }
            }).then(function(response) {
                if (response.done.length > 0) {
                    for (const entry of response.done) {
                        const line = $('<div></div>');
                        $('<strong></strong>').text('#' + entry.id + ' - ' + entry.name).appendTo(line);
                        line.append(' done');
                        $('#migration_protocol').append(line);
                        currentPercent += step;
                    }

                    updateProgressBar();
                    setTimeout(batchSync, 500);
                } else {
                    currentPercent = 100;
                    updateProgressBar();
                }
            });
        }

        updateProgressBar();
        $('#migration_log').show();

        batchSync();
    });

    $('.s3-sync-file-btn').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();

        const button = $(e.currentTarget);
        const url = button.data('url');

        jQuery.get(url, function() {
            button.html('<span class="dashicons dashicons-saved"></span>');
        });
    });
})(jQuery);
