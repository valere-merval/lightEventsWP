(function(blocks, element, components){
  var el = element.createElement;
  blocks.registerBlockType('lightevents/events', {
    edit: function(props){
      var a = props.attributes;
      function set(key){ return function(value){ var next = {}; next[key] = value; props.setAttributes(next); }; }
      return el('div', {className:'lightevents-editor-card'},
        el('strong', null, 'LightEvents - Liste d\'événements'),
        el(components.SelectControl, {label:'Vue', value:a.view, options:[{label:'Grille',value:'grid'},{label:'Liste / agenda',value:'list'},{label:'Calendrier',value:'calendar'},{label:'Carte',value:'map'}], onChange:set('view')}),
        el(components.TextControl, {label:'Pays', value:a.country || '', onChange:set('country')}),
        el(components.TextControl, {label:'Ville', value:a.city || '', onChange:set('city')}),
        el(components.TextControl, {label:'Catégorie', value:a.category || '', onChange:set('category')}),
        el(components.TextControl, {label:'Organisateur', value:a.organizer || '', onChange:set('organizer')}),
        el(components.TextControl, {label:'Limite', type:'number', value:a.limit || 12, onChange:function(v){ props.setAttributes({limit: parseInt(v || '12', 10)}); }})
      );
    },
    save: function(){ return null; }
  });
})(window.wp.blocks, window.wp.element, window.wp.components);
