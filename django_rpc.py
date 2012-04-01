from django.core import serializers
from django.utils import simplejson as json
from django.http import HttpResponse
from django.views.generic import View
from django.core.serializers.json import Serializer as JsonSerializer, DjangoJSONEncoder

class Serializer(JsonSerializer):
    def end_serialization(self):
        if json.__version__.split('.') >= ['2', '1', '3']:
            # Use JS strings to represent Python Decimal instances (ticket #16850)
            self.options.update({'use_decimal': False})
        data = { 'result': self.objects }
        json.dump(data, self.stream, cls=DjangoJSONEncoder, **self.options)

serializers.register_serializer('jsonrpc', Serializer)

class JSONResponseMixin(object):
    def render_to_response(self, context):
        "Returns a JSON response containing 'context' as payload"
        if not hasattr(context,'__iter__'): context = (context,)
        response = HttpResponse(mimetype='application/json')
        serializers.serialize('jsonrpc', context, stream=response)
        return response

class JSONResponseView(JSONResponseMixin, View):
    def post(self, request, *args, **kwargs):
        #print request.is_ajax()
        if( request.META.get('CONTENT_TYPE','').split(';')[0] == 'application/json'
            and hasattr(self, 'get_context_data')
            and callable(self.get_context_data)
        ):
            context = self.get_context_data(**kwargs)
            return self.render_to_response(context)
        else:
            return super(JSONResponseView,self).post(request, *args, **kwargs)

class JSONRPCServiceView(View):
    def post(self, request, *args, **kwargs):
        #print request.is_ajax()
        if( request.META.get('CONTENT_TYPE','').split(';')[0] == 'application/json'
            and hasattr(self, 'get_context_data')
            and callable(self.get_context_data)
        ):
            context = self.get_context_data(**kwargs)
            return self.render_to_response(context)
        else:
            return super(JSONRPCServiceView,self).post(request, *args, **kwargs)

    def render_to_response(self, context):
        "Returns a JSON response containing 'context' as payload"
        response = HttpResponse(mimetype='application/json')
        json.dump(context, response)
        return response

    def get_context_data(self, **kwargs):
        response = {}
        result = response['result'] = {}
        result['target'] = 'api'
        result['transport'] = 'POST'
        result['envelope'] = 'JSON-RPC-2.0'
        result['SMDVersion'] = '2.0'
        services = result['services'] = {}
        glob = globals()
        for k,v in glob.iteritems():
            if callable(v) and hasattr(v,'__jsonrpc') and getattr(v,'__jsonrpc') == True:
                target = 'api/' + k
                services[k] = { 'target': target }

        return response

def RemoteMethod(view):
    view.__jsonrpc = True
    return view

rpc_service = RemoteMethod(JSONRPCServiceView.as_view())
