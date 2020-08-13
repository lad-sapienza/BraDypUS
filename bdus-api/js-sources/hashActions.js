const hashActions = {
    
    getHash: function(what){
        
        var hash = location.hash;
        
        if(hash.length !== 0){

            // removes first 2 chars, ie. #/ and splits result in array
            hash = hash.substring(2).split('/');
            
            switch(what){
                
                // #/app => app
                case 'app':
                    return hash[0];
                
                // #/app/map/mapId
                case 'mapId':
                    if (hash[0] && hash[1] == 'map' && hash[2]){
                        return hash[2];
                    } else {
                        return false;
                    }
                
                // #/app/query/queryId
                case 'queryId':
                    if (hash[0] && hash[1] == 'query' && hash[2]){
                        return hash[2];
                    } else {
                        return false;
                    }
                
                // #/app/chart/chartId
                case 'chartId':
                    if (hash[0] && hash[1] == 'chart' && hash[2]){
                        return hash[2];
                    } else {
                        return false;
                    }
                
                // #/app/table/id
                case 'readId':
                    if (hash[0] && hash[1] && hash[2]){
                        return {app:hash[0], table: hash[1], id: hash[2], isIdField: hash[3]};
                    } else {
                        return false;
                    }
                
                default:
                return hash;
            }
        } else {
            return false;
        }
    },

    map2action(){
        if (this.getHash('mapId')){
            core.getJSON('saved_queries_ctrl', 'getById', {"id": this.getHash('mapId') }, false, function(data){
                if(data.status == 'success'){
                    core.runMod('geoface', [data.tb, data.text]);
                }
            });
            
        } else if (this.getHash('queryId')){
            core.getJSON('saved_queries_ctrl', 'getById', { "id": this.getHash('queryId') }, false, function(data){
                if(data.status === 'success'){
                    api.showResults(data.tb, 'type=encoded&q_encoded=' + data.text, core.tr('saved_queries') + ' (' + data.tb + ')');
                } else {
                    core.message(core.tr('saved_query_does_not_exist', [this.getHash('queryId')]), 'error', true);
                }
            });
            
        } else if (this.getHash('chartId')){
            core.runMod('chart', ['showChart', this.getHash('chartId')]);
            
        } else if (this.getHash('readId')){
            const hash_data = this.getHash('readId');
            api.record.read(prefix + hash_data.table, [hash_data.id], hash_data.isIdField);
            
        }
    }
};