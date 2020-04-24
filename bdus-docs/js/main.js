hljs.initHighlightingOnLoad();
hljs.initLineNumbersOnLoad();


$(document).ready(function(){

    $('#search-arg').on('search', function() {
        doSearch($(this).val());
    });
    $('#search-arg').on('keyup', function(){
        doSearch($(this).val());
    });

    function doSearch(filter) {
        if (filter && filter !== '') {
            $.each($('.searcheable li'), function(i, el){
            if ($(el).text().toLowerCase().indexOf(filter.toLowerCase()) > -1){
                $(el).removeClass('d-none');
            } else {
                $(el).addClass('d-none');
            }
            });
        } else {
            $('.searcheable li').removeClass('d-none');
        }
    }
});
