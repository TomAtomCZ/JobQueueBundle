document.addEventListener("DOMContentLoaded", function () {
    const jobOutput = document.getElementById('jobOutput');
    const jobStatus = document.getElementById('jobStatus');

    if (!jobOutput || !jobStatus) return;

    const STATUS_RUNNING = jobStatus.dataset.status_running.trim();
    const STATUS_PLANNED = jobStatus.dataset.status_planned.trim();

    let autoScrollEnabled = true;
    let previousOutput = '';
    let interval;

    // Scroll to input bottom on page load
    scrollToBottom(jobOutput);

    // Set auto scroll of output by if user has scrolled up in the output
    jobOutput.addEventListener('scroll', () => {
        const nearBottom = jobOutput.scrollHeight - jobOutput.scrollTop - jobOutput.clientHeight;
        autoScrollEnabled = nearBottom <= 10;
    });

    // Set interval for ajax update of the job page if job is planned or running
    if ([STATUS_RUNNING, STATUS_PLANNED].includes(jobStatus.innerHTML.trim())) {
        interval = setInterval(() => {
            ajaxUpdateOutput(jobOutput);
            if (autoScrollEnabled) {
                scrollToBottom(jobOutput);
            }
        }, jobStatus.innerHTML.trim() === STATUS_PLANNED ? 5000 : 500);
    }

    // Scroll to the bottom of the output
    function scrollToBottom(jobOutput) {
        jobOutput.scrollTo({top: jobOutput.scrollHeight, behavior: 'instant'});
    }

    // Update the job output when job is running
    function ajaxUpdateOutput(jobOutput) {
        const ajaxPath = jobOutput.dataset.ajax_path;
        fetch(ajaxPath, {method: 'GET'})
            .then(response => response.json())
            .then(data => {
                if (data.output && data.output.length > 0 && data.output !== previousOutput) {
                    // If the job is planned refresh the page
                    if (jobStatus.innerHTML.trim() === STATUS_PLANNED) {
                        setTimeout(() => window.location.reload(), 500);
                        return;
                    } else {
                        // Update output if the job is running
                        if (jobOutput.parentElement.style.display === 'none') {
                            jobOutput.parentElement.style.display = 'block';
                        }
                        jobOutput.innerHTML = data.output;
                        previousOutput = data.output;
                    }
                }

                // When the job is finished, clear the interval so no further requests are made and refresh the page
                if (data.finished) {
                    clearInterval(interval);
                    setTimeout(() => window.location.reload(), 500);
                }
            })
            .catch(error => {
                console.error('Error fetching output:', error);
            });
    }
});